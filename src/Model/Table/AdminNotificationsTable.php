<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\AdminNotification;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * AdminNotifications Model: the alerts for the administrators.
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Readers
 * @method \App\Model\Entity\AdminNotification newEmptyEntity()
 * @method \App\Model\Entity\AdminNotification newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\AdminNotification get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\AdminNotification|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class AdminNotificationsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('admin_notifications');
        $this->setDisplayField('type');
        $this->setPrimaryKey('id');

        $this->getSchema()->setColumnType('data', 'json');

        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);

        $this->belongsTo('Readers', [
            'className' => 'Users',
            'foreignKey' => 'read_by',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('type')
            ->maxLength('type', 40)
            ->requirePresence('type', 'create')
            ->notEmptyString('type');

        $validator
            ->scalar('dedupe_key')
            ->maxLength('dedupe_key', 100)
            ->allowEmptyString('dedupe_key');

        return $validator;
    }

    /**
     * The alerts nobody has read yet, newest first.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findUnread(SelectQuery $query): SelectQuery
    {
        return $query
            ->where([$this->aliasField('read_at') . ' IS' => null])
            ->orderBy([$this->aliasField('created') => 'DESC', $this->aliasField('id') => 'DESC']);
    }

    /**
     * Raise an alert, unless the same thing was already reported - read or not.
     *
     * @param string $type One of the AdminNotification::TYPE_* constants.
     * @param string $key What the alert is about.
     * @param array<string, mixed> $data What is needed to word it.
     * @return bool Whether a new alert was raised.
     */
    public function raise(string $type, string $key, array $data): bool
    {
        if ($this->exists(['dedupe_key' => $key])) {
            return false;
        }
        $this->saveOrFail($this->newEntity(['type' => $type, 'dedupe_key' => $key, 'data' => $data]));

        return true;
    }

    /**
     * Mark unread alerts as read.
     *
     * @param int|null $userId The administrator reading them.
     * @param int|null $id One alert; all of them when left out.
     * @return int Alerts marked.
     */
    public function markRead(?int $userId, ?int $id = null): int
    {
        $conditions = ['read_at IS' => null];
        if ($id !== null) {
            $conditions['id'] = $id;
        }

        return $this->updateAll(['read_at' => DateTime::now(), 'read_by' => $userId], $conditions);
    }

    /**
     * Close an unread alert that no longer applies. Its key is let go, so the
     * same thing is reported again if it comes back.
     *
     * @param \App\Model\Entity\AdminNotification $notification Alert.
     * @return void
     */
    public function resolve(AdminNotification $notification): void
    {
        $this->updateAll(
            ['read_at' => DateTime::now(), 'read_by' => null, 'dedupe_key' => null],
            ['id' => $notification->id, 'read_at IS' => null]
        );
    }
}
