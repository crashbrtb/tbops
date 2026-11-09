<?php
declare(strict_types=1);

namespace App\Controller;

use App\Utility\GameNumber;
use App\Model\Table\BankAccountsTable;
use App\Model\Table\BankApprovalLogsTable;
use App\Model\Table\BankTransactionsTable;
use App\Model\Table\ConfigTable;
use App\Model\Table\MembersTable;
use App\Service\BankingService;
use App\Model\Entity\BankTransaction;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\MethodNotAllowedException;
use Cake\Http\Exception\NotFoundException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\TableRegistry;
use RuntimeException;

class BankController extends AppController
{
    protected BankingService $banking;

    /**
     * Cache simples para valores de configuração.
     *
     * @var array<string, float>
     */
    protected array $configCache = [];

    protected MembersTable $Members;
    protected BankAccountsTable $BankAccounts;
    protected BankTransactionsTable $BankTransactions;
    protected BankApprovalLogsTable $BankApprovalLogs;
    protected ConfigTable $Config;

    public function initialize(): void
    {
        parent::initialize();

        $locator = TableRegistry::getTableLocator();
        $this->Members = $locator->get('Members');
        $this->BankAccounts = $locator->get('BankAccounts');
        $this->BankTransactions = $locator->get('BankTransactions');
        $this->BankApprovalLogs = $locator->get('BankApprovalLogs');
        $this->Config = $locator->get('Config');

        $this->banking = new BankingService($this->BankAccounts);
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        // Nenhuma action deste controller é pública.
    }

    public function index()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to access the bank.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $members = $this->Members->find()
            ->contain(['BankAccounts'])
            ->orderAsc('Members.player')
            ->all();

        $accountsSumQuery = $this->BankAccounts->find();
        $accountsSumQuery->select([
            'total' => $accountsSumQuery->func()->coalesce([
                $accountsSumQuery->func()->sum('BankAccounts.balance'),
                0,
            ]),
        ]);
        $accountsTotal = $accountsSumQuery->first()->get('total');
        $totalSilver = (int)round((float)($accountsTotal ?? 0));

        $feeSumQuery = $this->BankTransactions->find();
        $feeSumQuery->select([
            'total' => $feeSumQuery->func()->coalesce([
                $feeSumQuery->func()->sum('BankTransactions.fee'),
                0,
            ]),
        ])->where([
            'BankTransactions.status' => BankTransactionsTable::STATUS_APPROVED,
        ]);
        $feesTotal = $feeSumQuery->first()->get('total');
        $totalFees = (int)round((float)($feesTotal ?? 0));

        $isAdmin = $this->isAdmin($userId);

        $this->set(compact(
            'members',
            'totalSilver',
            'totalFees',
            'userId',
            'isAdmin'
        ));
    }

    public function history(int $memberId)
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to view transaction history.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        // Only administrators can view history
        if (!$this->isAdmin($userId)) {
            throw new ForbiddenException(__('Only administrators can view transaction history.'));
        }

        $member = $this->Members->find()
            ->contain(['BankAccounts'])
            ->where(['Members.id' => $memberId])
            ->first();

        if (!$member) {
            throw new NotFoundException(__('Member not found.'));
        }

        $transactions = $this->BankTransactions->find()
            ->contain([
                'Users',
                'Members',
                'DestinationMembers',
                'BankApprovalLogs' => ['AdminUsers'],
            ])
            ->where([
                'OR' => [
                    'BankTransactions.member_id' => $memberId,
                    'BankTransactions.destination_member_id' => $memberId,
                ],
            ])
            ->orderDesc('BankTransactions.created')
            ->all();

        $this->set(compact('member', 'transactions', 'memberId'));
    }

    public function deposit()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to deposit Silver.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $isAdmin = $this->isAdmin($userId);
        $ownMembers = $this->membersForUser($userId);
        $allMembers = $isAdmin ? $this->membersForUser($userId, true) : $ownMembers;

        if (empty($allMembers)) {
            $this->Flash->warning(__('You do not have members available for banking operations.'));
        }

        $depositFee = (int)$this->configValue('deposit_fee');
        $caravanFee = (int)$this->configValue('caravan_fee');

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $memberId = (int)($data['member_id'] ?? 0);

            try {
                $member = $this->assertMemberOwnership($memberId, $userId, $isAdmin);
            } catch (ForbiddenException | RecordNotFoundException $exception) {
                $this->Flash->error($exception->getMessage());
                return $this->redirect(['action' => 'deposit']);
            }

            $amountInput = trim((string)($data['amount'] ?? ''));
            $caravanSelected = (bool)($data['caravan'] ?? false);
            $description = trim((string)($data['description'] ?? ''));

            do {
                if (!$this->isIntegerAmount($amountInput)) {
                    $this->Flash->error(__('Enter a valid integer amount in millions of Silver.'));
                    break;
                }

                $amount = (int)$amountInput;
                if ($amount <= 0) {
                    $this->Flash->error(__('Amount must be greater than zero.'));
                    break;
                }

                // If caravan is selected, deposit fee is 0
                $actualDepositFee = $caravanSelected ? 0 : $depositFee;
                $caravanTax = $caravanSelected ? intdiv($amount * $caravanFee, 100) : 0;
                $totalFee = $actualDepositFee + $caravanTax;
                $finalAmount = $amount - $totalFee;

                if ($finalAmount <= 0) {
                    $this->Flash->error(__('The total fee cannot be greater than the deposit.'));
                    break;
                }

                $status = $isAdmin ? BankTransactionsTable::STATUS_APPROVED : BankTransactionsTable::STATUS_PENDING;

                $transaction = $this->BankTransactions->newEmptyEntity();
                $transaction = $this->BankTransactions->patchEntity($transaction, [
                    'member_id' => $member->id,
                    'user_id' => $userId,
                    'type' => BankTransactionsTable::TYPE_DEPOSIT,
                    'amount' => $amount,
                    'fee' => $totalFee,
                    'final_amount' => $finalAmount,
                    'description' => $description ?: null,
                    'status' => $status,
                ]);

                if ($this->BankTransactions->save($transaction)) {
                    if ($status === BankTransactionsTable::STATUS_APPROVED) {
                        try {
                            $this->banking->addToBalance($member->id, (int)$finalAmount);
                        } catch (\RuntimeException $e) {
                            $this->Flash->error($e->getMessage());
                            break;
                        }
                    }

                    $message = $isAdmin
                        ? __('Deposit completed successfully.')
                        : __('Deposit registered and waiting for administrator approval.');
                    $this->Flash->success($message);

                    return $this->redirect(['action' => 'statement']);
                }

                $errors = $transaction->getErrors();
                if (!empty($errors)) {
                    foreach ($errors as $field => $fieldErrors) {
                        foreach ($fieldErrors as $error) {
                            $this->Flash->error($error);
                        }
                    }
                } else {
                    $this->Flash->error(__('Unable to register this deposit. Please review the information and try again.'));
                }
            } while (false);
            
            // Se chegou aqui, houve erro - redireciona para mostrar os erros
            return $this->redirect(['action' => 'deposit']);
        }

        $this->set(compact(
            'ownMembers',
            'allMembers',
            'isAdmin',
            'depositFee',
            'caravanFee'
        ));
    }

    public function withdraw()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to withdraw Silver.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $isAdmin = $this->isAdmin($userId);
        $ownMembers = $this->membersForUser($userId);
        $allMembers = $isAdmin ? $this->membersForUser($userId, true) : $ownMembers;
        $withdrawFee = (int)$this->configValue('withdrawal_fee');

        $memberBalances = $this->loadMemberBalances($allMembers);

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $memberId = (int)($data['member_id'] ?? 0);

            try {
                $member = $this->assertMemberOwnership($memberId, $userId, $isAdmin);
            } catch (ForbiddenException | RecordNotFoundException $exception) {
                $this->Flash->error($exception->getMessage());
                return $this->redirect(['action' => 'withdraw']);
            }

            $amountInput = trim((string)($data['amount'] ?? ''));
            $description = trim((string)($data['description'] ?? ''));

            do {
                if (!$this->isIntegerAmount($amountInput)) {
                    $this->Flash->error(__('Enter a valid integer amount in millions of Silver.'));
                    break;
                }

                $amount = (int)$amountInput;
                if ($amount <= 0) {
                    $this->Flash->error(__('Amount must be greater than zero.'));
                    break;
                }

                $totalDeduction = $amount + $withdrawFee;
                $availableBalance = $this->banking->getBalance($member->id);

                if ($totalDeduction > $availableBalance) {
                    $this->Flash->error(__('⚠️ INSUFFICIENT BALANCE ⚠️') . "\n\n" . 
                        __('Available balance: {0}', GameNumber::silver($availableBalance)) . "\n" .
                        __('Withdrawal amount: {0}', GameNumber::silver($amount)) . "\n" .
                        __('Fee: {0}', GameNumber::silver($withdrawFee)) . "\n" .
                        __('Total required: {0}', GameNumber::silver($totalDeduction)) . "\n\n" .
                        __('Please reduce the withdrawal amount.'));
                    return $this->redirect(['action' => 'withdraw']);
                }

                $status = $isAdmin ? BankTransactionsTable::STATUS_APPROVED : BankTransactionsTable::STATUS_PENDING;

                $transaction = $this->BankTransactions->newEmptyEntity();
                $transaction = $this->BankTransactions->patchEntity($transaction, [
                    'member_id' => $member->id,
                    'user_id' => $userId,
                    'type' => BankTransactionsTable::TYPE_WITHDRAWAL,
                    'amount' => $amount,
                    'fee' => $withdrawFee,
                    'final_amount' => $totalDeduction,
                    'description' => $description ?: null,
                    'status' => $status,
                ]);

                if ($this->BankTransactions->save($transaction)) {
                    if ($status === BankTransactionsTable::STATUS_APPROVED) {
                        try {
                            $this->banking->subtractFromBalance($member->id, (int)$totalDeduction);
                        } catch (\RuntimeException $e) {
                            $this->Flash->error($e->getMessage());
                            break;
                        }
                    }

                    $message = $isAdmin
                        ? __('Withdrawal completed successfully.')
                        : __('Withdrawal registered and waiting for administrator approval.');
                    $this->Flash->success($message);

                    return $this->redirect(['action' => 'statement']);
                }

                $errors = $transaction->getErrors();
                if (!empty($errors)) {
                    foreach ($errors as $field => $fieldErrors) {
                        foreach ($fieldErrors as $error) {
                            $this->Flash->error($error);
                        }
                    }
                } else {
                    $this->Flash->error(__('Unable to register this withdrawal. Please try again.'));
                }
            } while (false);
            
            // Se chegou aqui, houve erro - redireciona para mostrar os erros
            return $this->redirect(['action' => 'withdraw']);
        }

        $this->set(compact(
            'allMembers',
            'memberBalances',
            'withdrawFee',
            'isAdmin'
        ));
    }

    public function transfer()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to transfer Silver.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $isAdmin = $this->isAdmin($userId);
        $sourceMembers = $this->membersForUser($userId);

        if (empty($sourceMembers)) {
            $this->Flash->warning(__('You do not have members available for transfers.'));
        }

        $destinationMembers = $this->membersForUser($userId, true, true);
        $memberBalances = $this->loadMemberBalances($sourceMembers);
        $transferFee = (int)$this->configValue('transfer_fee');

        $confirmationData = null;

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $confirmed = (bool)($data['confirm'] ?? false);
            $sourceId = (int)($data['source_member_id'] ?? 0);
            $destinationId = (int)($data['destination_member_id'] ?? 0);
            $amountInput = trim((string)($data['amount'] ?? ''));
            $description = trim((string)($data['description'] ?? ''));

            try {
                $sourceMember = $this->assertMemberOwnership($sourceId, $userId, $isAdmin);
            } catch (ForbiddenException | RecordNotFoundException $exception) {
                $this->Flash->error($exception->getMessage());
                return $this->redirect(['action' => 'transfer']);
            }

            $destinationMember = $this->Members->find()
                ->where(['Members.id' => $destinationId])
                ->first();

            do {
                if (!$destinationMember) {
                    $this->Flash->error(__('Destination member not found.'));
                    break;
                }

                if ($destinationMember->id === $sourceMember->id) {
                    $this->Flash->error(__('Source and destination members must be different.'));
                    break;
                }

                if (!$this->isIntegerAmount($amountInput)) {
                    $this->Flash->error(__('Enter a valid integer amount in millions of Silver.'));
                    break;
                }

                $amount = (int)$amountInput;
                if ($amount <= 0) {
                    $this->Flash->error(__('Amount must be greater than zero.'));
                    break;
                }

                $totalDeduction = $amount + $transferFee;
                $availableBalance = $this->banking->getBalance($sourceMember->id);

                if ($availableBalance <= 0) {
                    $this->Flash->error(__('There is no balance available for transfers.'));
                    break;
                }

                if ($totalDeduction > $availableBalance) {
                    $this->Flash->error(__('Insufficient balance for this transfer.'));
                    break;
                }

                if (!$confirmed) {
                    $confirmationData = [
                        'source' => $sourceMember,
                        'destination' => $destinationMember,
                        'amount' => $amount,
                        'fee' => $transferFee,
                        'totalDeduction' => $totalDeduction,
                        'description' => $description,
                    ];
                } else {
                    $transaction = $this->BankTransactions->newEmptyEntity();
                    $transaction = $this->BankTransactions->patchEntity($transaction, [
                        'member_id' => $sourceMember->id,
                        'destination_member_id' => $destinationMember->id,
                        'user_id' => $userId,
                        'type' => BankTransactionsTable::TYPE_TRANSFER,
                        'amount' => $amount,
                        'fee' => $transferFee,
                        'final_amount' => $amount,
                        'description' => $description ?: null,
                        'status' => BankTransactionsTable::STATUS_APPROVED,
                    ]);

                    if ($this->BankTransactions->save($transaction)) {
                        $this->banking->transfer($sourceMember->id, $destinationMember->id, (int)$amount, (int)$transferFee);
                        $this->Flash->success(__('Transfer completed successfully.'));
                        return $this->redirect(['action' => 'statement']);
                    }

                    $this->Flash->error(__('Unable to complete this transfer. Please try again.'));
                }
            } while (false);
        }

        $this->set(compact(
            'sourceMembers',
            'destinationMembers',
            'memberBalances',
            'transferFee',
            'confirmationData'
        ));
    }

    public function statement()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to view statements.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $members = $this->membersForUser($userId);

        if (empty($members)) {
            $this->Flash->warning(__('You do not have registered members.'));
            return $this->redirect(['action' => 'index']);
        }

        $memberId = (int)($this->request->getQuery('member_id') ?? array_key_first($members));

        try {
            $member = $this->assertMemberOwnership($memberId, $userId, $this->isAdmin($userId));
        } catch (ForbiddenException | RecordNotFoundException $exception) {
            $this->Flash->error($exception->getMessage());
            return $this->redirect(['action' => 'statement']);
        }

        $transactions = $this->BankTransactions->find()
            ->contain([
                'Members',
                'DestinationMembers',
                'BankApprovalLogs' => ['AdminUsers'],
            ])
            ->where([
                'OR' => [
                    'BankTransactions.member_id' => $memberId,
                    'BankTransactions.destination_member_id' => $memberId,
                ],
            ])
            ->orderDesc('BankTransactions.created')
            ->all();

        // Get current balance using BankingService
        $balance = $this->banking->getBalance($memberId);

        $this->set(compact('member', 'members', 'memberId', 'transactions', 'balance'));
    }

    protected function membersForUser(int $userId, bool $allowAllForAdmins = false, bool $includeOrphanMembers = false): array
    {
        $query = $this->Members->find('list', keyField: 'id', valueField: 'player')
            ->orderAsc('Members.player');

        if (!$allowAllForAdmins || !$this->isAdmin($userId)) {
            $conditions = ['Members.user_id' => $userId];
            if ($includeOrphanMembers) {
                $conditions = [
                    'OR' => [
                        ['Members.user_id' => $userId],
                        ['Members.user_id IS' => null],
                    ],
                ];
            }
            $query->where($conditions);
        }

        return $query->toArray();
    }

    protected function assertMemberOwnership(int $memberId, int $userId, bool $isAdminAllowed = false)
    {
        $member = $this->Members->get($memberId);
        if (!$member) {
            throw new NotFoundException(__('Member not found.'));
        }

        if ($member->user_id !== $userId && !($isAdminAllowed && $this->isAdmin($userId))) {
            throw new ForbiddenException(__('You can only operate with your own members.'));
        }

        return $member;
    }

    protected function configValue(string $param, float $default = 0.0): float
    {
        if (array_key_exists($param, $this->configCache)) {
            return $this->configCache[$param];
        }

        $record = $this->Config->find()
            ->select(['value'])
            ->where(['param' => $param])
            ->first();

        $this->configCache[$param] = $record ? (float)$record->value : $default;

        return $this->configCache[$param];
    }

    protected function isIntegerAmount(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }

    protected function loadMemberBalances(array $memberOptions): array
    {
        if (empty($memberOptions)) {
            return [];
        }

        $memberIds = array_keys($memberOptions);
        $accounts = $this->BankAccounts->find()
            ->select(['member_id', 'balance'])
            ->where(['member_id IN' => $memberIds])
            ->all()
            ->combine('member_id', 'balance')
            ->toArray();

        $balances = [];
        foreach ($memberIds as $memberId) {
            $balances[$memberId] = isset($accounts[$memberId]) ? (int)round((float)$accounts[$memberId]) : 0;
        }

        return $balances;
    }

    public function approvals()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to view approvals.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        if (!$this->isAdmin($userId)) {
            throw new ForbiddenException(__('Only administrators can access the banking approvals.'));
        }

        $pendingTransactions = $this->BankTransactions->find()
            ->contain([
                'Members',
                'Users',
                'BankApprovalLogs' => ['AdminUsers'],
            ])
            ->where([
                'BankTransactions.status' => BankTransactionsTable::STATUS_PENDING,
                'BankTransactions.type IN' => [
                    BankTransactionsTable::TYPE_DEPOSIT,
                    BankTransactionsTable::TYPE_WITHDRAWAL,
                ],
            ])
            ->orderAsc('BankTransactions.created')
            ->all();

        $this->set(compact('pendingTransactions'));
    }

    public function approve(int $id)
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to approve transactions.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        if (!$this->isAdmin($userId)) {
            throw new ForbiddenException(__('Only administrators can approve transactions.'));
        }

        $this->request->allowMethod(['post']);
        $this->autoRender = false;
        $this->viewBuilder()->disableAutoLayout();

        // Pre-set redirect response to prevent error page rendering
        $redirectResponse = $this->redirect(['controller' => 'Bank', 'action' => 'approvals']);

        try {
            $transaction = $this->getPendingTransaction($id);

            if ($transaction->status !== BankTransactionsTable::STATUS_PENDING) {
                $this->Flash->error(__('This transaction is not pending approval.'));
                return $redirectResponse;
            }

            try {
                $this->applyTransactionEffect($transaction);
            } catch (RuntimeException $exception) {
                $this->Flash->error($exception->getMessage());
                return $redirectResponse;
            }

            $transaction->status = BankTransactionsTable::STATUS_APPROVED;
            if ($this->BankTransactions->save($transaction)) {
                try {
                    $this->logApproval($transaction, 'approved');
                } catch (\Exception $e) {
                    // Log error but don't fail the approval
                    \Cake\Log\Log::error('Failed to log approval: ' . $e->getMessage());
                }
                $this->Flash->success(__('Transaction approved and applied successfully.'));
            } else {
                $this->Flash->error(__('Unable to approve this transaction.'));
            }
        } catch (NotFoundException $e) {
            $this->Flash->error(__('Transaction not found.'));
        } catch (ForbiddenException $e) {
            $this->Flash->error($e->getMessage());
        } catch (MethodNotAllowedException $e) {
            $this->Flash->error($e->getMessage());
        } catch (\Exception $e) {
            $this->Flash->error(__('An error occurred while processing the approval.'));
            \Cake\Log\Log::error('Approval error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
        }

        return $redirectResponse;
    }

    public function reject(int $id)
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            $this->Flash->error(__('You must be logged in to reject transactions.'));
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        if (!$this->isAdmin($userId)) {
            throw new ForbiddenException(__('Only administrators can reject transactions.'));
        }

        $this->request->allowMethod(['post']);
        $this->autoRender = false;

        $transaction = $this->getPendingTransaction($id);
        $transaction->status = BankTransactionsTable::STATUS_REJECTED;

        if ($this->BankTransactions->save($transaction)) {
            $details = [];
            $reason = trim((string)($this->request->getData('reason') ?? ''));
            if ($reason !== '') {
                $details['reason'] = $reason;
            }

            $this->logApproval($transaction, 'rejected', $details ?: null);
            $this->Flash->success(__('Transaction rejected.'));
        } else {
            $this->Flash->error(__('Unable to reject this transaction.'));
        }

        return $this->redirect(['controller' => 'Bank', 'action' => 'approvals']);
    }

    protected function getPendingTransaction(int $id): BankTransaction
    {
        $transaction = $this->BankTransactions->find()
            ->where(['BankTransactions.id' => $id])
            ->contain(['Members'])
            ->first();

        if (!$transaction) {
            throw new NotFoundException(__('Transaction not found.'));
        }

        if ($transaction->status !== BankTransactionsTable::STATUS_PENDING) {
            throw new MethodNotAllowedException(__('This transaction is not pending.'));
        }

        if (!in_array($transaction->type, [BankTransactionsTable::TYPE_DEPOSIT, BankTransactionsTable::TYPE_WITHDRAWAL], true)) {
            throw new MethodNotAllowedException(__('Only deposits and withdrawals require approval.'));
        }

        return $transaction;
    }

    protected function applyTransactionEffect(BankTransaction $transaction): void
    {
        if ($transaction->type === BankTransactionsTable::TYPE_DEPOSIT) {
            $finalAmount = (float)$transaction->final_amount;
            $this->banking->addToBalance($transaction->member_id, (int)round($finalAmount));
            return;
        }

        if ($transaction->type === BankTransactionsTable::TYPE_WITHDRAWAL) {
            $finalAmount = (float)$transaction->final_amount;
            $this->banking->subtractFromBalance($transaction->member_id, (int)round($finalAmount));
            return;
        }

        throw new RuntimeException(__('Unsupported transaction type.'));
    }

    protected function logApproval(BankTransaction $transaction, string $action, ?array $details = null): void
    {
        $log = $this->BankApprovalLogs->newEntity([
            'bank_transaction_id' => $transaction->id,
            'admin_user_id' => $this->currentUserId(),
            'action' => $action,
            'original_values' => $details ? json_encode($details) : null,
        ]);
        
        if (!$this->BankApprovalLogs->save($log)) {
            \Cake\Log\Log::warning('Failed to save approval log for transaction ' . $transaction->id);
        }
    }
}

