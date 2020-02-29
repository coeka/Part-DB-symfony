<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2020 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\EventSubscriber;

use App\Entity\Attachments\Attachment;
use App\Entity\Attachments\AttachmentType;
use App\Entity\Base\AbstractDBElement;
use App\Entity\Base\AbstractPartsContainingDBElement;
use App\Entity\Base\AbstractStructuralDBElement;
use App\Entity\LogSystem\AbstractLogEntry;
use App\Entity\LogSystem\CollectionElementDeleted;
use App\Entity\LogSystem\ElementCreatedLogEntry;
use App\Entity\LogSystem\ElementDeletedLogEntry;
use App\Entity\LogSystem\ElementEditedLogEntry;
use App\Entity\Parts\PartLot;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\PriceInformations\Pricedetail;
use App\Entity\UserSystem\User;
use App\Services\LogSystem\EventCommentHelper;
use App\Services\LogSystem\EventLogger;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\SerializerInterface;

class EventLoggerSubscriber implements EventSubscriber
{
    /** @var array The given fields will not be saved, because they contain sensitive informations */
    protected const FIELD_BLACKLIST = [
        User::class => ['password', 'need_pw_change', 'googleAuthenticatorSecret', 'backupCodes', 'trustedDeviceCookieVersion', 'pw_reset_token', 'backupCodesGenerationDate'],
    ];

    /** @var array If elements of the given class are deleted, a log for the given fields will be triggered */
    protected const TRIGGER_ASSOCIATION_LOG_WHITELIST = [
        PartLot::class => ['part'],
        Orderdetail::class => ['part'],
        Pricedetail::class => ['orderdetail'],
        Attachment::class => ['element'],
    ];

    protected const MAX_STRING_LENGTH = 2000;

    protected $logger;
    protected $serializer;
    protected $eventCommentHelper;
    protected $save_changed_fields;
    protected $save_changed_data;
    protected $save_removed_data;
    protected $propertyAccessor;

    public function __construct(EventLogger $logger, SerializerInterface $serializer, EventCommentHelper $commentHelper,
        bool $save_changed_fields, bool $save_changed_data, bool $save_removed_data, PropertyAccessorInterface $propertyAccessor)
    {
        $this->logger = $logger;
        $this->serializer = $serializer;
        $this->eventCommentHelper = $commentHelper;
        $this->propertyAccessor = $propertyAccessor;

        $this->save_changed_fields = $save_changed_fields;
        $this->save_changed_data = $save_changed_data;
        $this->save_removed_data = $save_removed_data;
    }

    public function onFlush(OnFlushEventArgs $eventArgs)
    {
        $em = $eventArgs->getEntityManager();
        $uow = $em->getUnitOfWork();

        /*
         * Log changes and deletions of entites.
         * We can not log persist here, because the entities do not have IDs yet...
         */

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->validEntity($entity)) {
                $this->logElementEdited($entity, $em);
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->validEntity($entity)) {
                $this->logElementDeleted($entity, $em);
            }
        }

        $uow->computeChangeSets();
    }

    public function postPersist(LifecycleEventArgs $args)
    {
        //Create an log entry, we have to do this post persist, cause we have to know the ID

        /** @var AbstractDBElement $entity */
        $entity = $args->getObject();
        if ($this->validEntity($entity)) {
            $log = new ElementCreatedLogEntry($entity);
            //Add user comment to log entry
            if ($this->eventCommentHelper->isMessageSet()) {
                $log->setComment($this->eventCommentHelper->getMessage());
            }
            $this->logger->log($log);
        }
    }

    public function postFlush(PostFlushEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();
        // If the we have added any ElementCreatedLogEntries added in postPersist, we flush them here.
        if ($uow->hasPendingInsertions()) {
            $em->flush();
        }

        //Clear the message provided by user.
        $this->eventCommentHelper->clearMessage();
    }

    protected function logElementDeleted(AbstractDBElement $entity, EntityManagerInterface $em): void
    {
        $log = new ElementDeletedLogEntry($entity);
        //Add user comment to log entry
        if ($this->eventCommentHelper->isMessageSet()) {
            $log->setComment($this->eventCommentHelper->getMessage());
        }
        if ($this->save_removed_data) {
            //The 4th param is important here, as we delete the element...
            $this->saveChangeSet($entity, $log, $em, true);
        }
        $this->logger->log($log);

        //Check if we have to log CollectionElementDeleted entries
        if ($this->save_changed_data) {
            $metadata = $em->getClassMetadata(get_class($entity));
            $mappings = $metadata->getAssociationMappings();
            //Check if class is whitelisted for CollectionElementDeleted entry
            foreach (static::TRIGGER_ASSOCIATION_LOG_WHITELIST as $class => $whitelist) {
                if (is_a($entity, $class)) {
                    //Check names
                    foreach ($mappings as $field => $mapping) {
                        if (in_array($field, $whitelist)) {
                            $changed = $this->propertyAccessor->getValue($entity, $field);
                            $log = new CollectionElementDeleted($changed, $mapping['inversedBy'], $entity);
                            $this->logger->log($log);
                        }
                    }
                }
            }
        }
    }

    protected function logElementEdited(AbstractDBElement $entity, EntityManagerInterface $em): void
    {
        $uow = $em->getUnitOfWork();

        $log = new ElementEditedLogEntry($entity);
        if ($this->save_changed_data) {
            $this->saveChangeSet($entity, $log, $em);
        } elseif ($this->save_changed_fields) {
            $changed_fields = array_keys($uow->getEntityChangeSet($entity));
            $log->setChangedFields($changed_fields);
        }
        //Add user comment to log entry
        if ($this->eventCommentHelper->isMessageSet()) {
            $log->setComment($this->eventCommentHelper->getMessage());
        }
        $this->logger->log($log);
    }

    /**
     * Check if the given element class has restrictions to its fields
     * @param  AbstractDBElement  $element
     * @return bool True if there are restrictions, and further checking is needed
     */
    public function hasFieldRestrictions(AbstractDBElement $element): bool
    {
        foreach (static::FIELD_BLACKLIST as $class => $blacklist) {
            if (is_a($element, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter out every forbidden field and return the cleaned array.
     * @param  AbstractDBElement  $element
     * @param  array  $fields
     * @return array
     */
    protected function filterFieldRestrictions(AbstractDBElement $element, array $fields): array
    {
        if (!$this->hasFieldRestrictions($element)) {
            return $fields;
        }

        return array_filter($fields, function ($value, $key) use ($element) {
            //Associative array (save changed data) case
            if (is_string($key)) {
                return $this->shouldFieldBeSaved($element, $key);
            }

            return $this->shouldFieldBeSaved($element, $value);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Checks if the field of the given element should be saved (if it is not blacklisted).
     * @param  AbstractDBElement  $element
     * @param  string  $field_name
     * @return bool
     */
    public function shouldFieldBeSaved(AbstractDBElement $element, string $field_name): bool
    {
        foreach (static::FIELD_BLACKLIST as $class => $blacklist) {
            if (is_a($element, $class) && in_array($field_name, $blacklist)) {
                return false;
            }
        }

        //By default allow every field.
        return true;
    }

    protected function saveChangeSet(AbstractDBElement $entity, AbstractLogEntry $logEntry, EntityManagerInterface $em, $element_deleted = false): void
    {
        $uow = $em->getUnitOfWork();

        if (!$logEntry instanceof ElementEditedLogEntry && !$logEntry instanceof ElementDeletedLogEntry) {
            throw new \InvalidArgumentException('$logEntry must be ElementEditedLogEntry or ElementDeletedLogEntry!');
        }

        if ($element_deleted) { //If the element was deleted we can use getOriginalData to save its content
            $old_data = $uow->getOriginalEntityData($entity);
        } else { //Otherwise we have to get it from entity changeset
            $changeSet = $uow->getEntityChangeSet($entity);
            $old_data = array_diff(array_combine(array_keys($changeSet), array_column($changeSet, 0)), [null]);
        }
        $this->filterFieldRestrictions($entity, $old_data);

        //Restrict length of string fields, to save memory...
        $old_data = array_map(function ($value) {
            if (is_string($value)) {
                return mb_strimwidth($value, 0, self::MAX_STRING_LENGTH, '...');
            }

            return $value;
        }, $old_data);

        $logEntry->setOldData($old_data);
    }
    /**
     * Check if the given entity can be logged.
     * @param object $entity
     * @return bool True, if the given entity can be logged.
     */
    protected function validEntity(object $entity): bool
    {
        //Dont log logentries itself!
        if ($entity instanceof AbstractDBElement && !$entity instanceof AbstractLogEntry) {
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getSubscribedEvents()
    {
        return[
            Events::onFlush,
            Events::postPersist,
            Events::postFlush
        ];
    }
}