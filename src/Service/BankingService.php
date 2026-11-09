<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\BankAccount;
use App\Model\Table\BankAccountsTable;
use Cake\ORM\TableRegistry;
use RuntimeException;

/**
 * Serviço utilitário para lidar com saldos dos membros.
 */
class BankingService
{
    public const SCALE = 2;

    protected BankAccountsTable $BankAccounts;

    public function __construct(?BankAccountsTable $bankAccounts = null)
    {
        $this->BankAccounts = $bankAccounts ?? TableRegistry::getTableLocator()->get('BankAccounts');
    }

    /**
     * Recupera o saldo atual do membro.
     */
    public function getBalance(int $memberId): int
    {
        $account = $this->BankAccounts->find()
            ->where(['member_id' => $memberId])
            ->first();

        return $account ? (int)round((float)$account->balance) : 0;
    }

    /**
     * Adiciona um valor ao saldo do membro.
     */
    public function addToBalance(int $memberId, int $amount): BankAccount
    {
        if ($amount < 0) {
            throw new RuntimeException('Amount must be positive.');
        }

        return $this->updateBalance($memberId, $amount);
    }

    /**
     * Subtrai um valor do saldo do membro.
     */
    public function subtractFromBalance(int $memberId, int $amount): BankAccount
    {
        if ($amount < 0) {
            throw new RuntimeException('Amount must be positive.');
        }

        return $this->updateBalance($memberId, -$amount);
    }

    /**
     * Debita uma dívida do membro, como a multa de um torneio: o saldo pode ficar
     * negativo, e o membro a quita depositando.
     */
    public function chargeDebt(int $memberId, int $amount): BankAccount
    {
        if ($amount < 0) {
            throw new RuntimeException('Amount must be positive.');
        }

        return $this->updateBalance($memberId, -$amount, true);
    }

    /**
     * Atualiza o saldo de forma transacional.
     */
    protected function updateBalance(int $memberId, int $delta, bool $allowNegative = false): BankAccount
    {
        return $this->BankAccounts->getConnection()->transactional(function () use ($memberId, $delta, $allowNegative) {
            $account = $this->fetchAccountForUpdate($memberId);
            $currentBalance = (int)round((float)$account->balance);
            $newBalance = $currentBalance + $delta;

            // Only a debit can lack balance: a credit always goes in, so a member
            // in debt (a tournament fine) pays it off in part or in whole.
            if ($delta < 0 && $newBalance < 0 && !$allowNegative) {
                throw new RuntimeException('Insufficient balance for this operation.');
            }

            $account->balance = $newBalance;

            return $this->BankAccounts->saveOrFail($account);
        });
    }

    /**
     * Transfere saldo entre dois membros em uma única transação.
     */
    public function transfer(int $sourceMemberId, int $destinationMemberId, int $amount, int $fee = 0): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }

        $this->BankAccounts->getConnection()->transactional(function () use ($sourceMemberId, $destinationMemberId, $amount, $fee) {
            $sourceAccount = $this->fetchAccountForUpdate($sourceMemberId);
            $destinationAccount = $this->fetchAccountForUpdate($destinationMemberId);

            $sourceBalance = (int)round((float)$sourceAccount->balance) - ($amount + $fee);
            if ($sourceBalance < 0) {
                throw new RuntimeException('Insufficient balance for this transfer.');
            }

            $destinationBalance = (int)round((float)$destinationAccount->balance) + $amount;

            $sourceAccount->balance = $sourceBalance;
            $destinationAccount->balance = $destinationBalance;

            $this->BankAccounts->saveOrFail($sourceAccount);
            $this->BankAccounts->saveOrFail($destinationAccount);
        });
    }

    protected function fetchAccountForUpdate(int $memberId): BankAccount
    {
        $account = $this->BankAccounts->find()
            ->where(['member_id' => $memberId])
            ->applyOptions(['forUpdate' => true])
            ->first();

        if (!$account) {
            $account = $this->BankAccounts->newEntity([
                'member_id' => $memberId,
                'balance' => 0,
            ]);
        }

        return $account;
    }
}

