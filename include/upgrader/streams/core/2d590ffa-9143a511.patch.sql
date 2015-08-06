/**
 * @version v1.10.0
 * @title Add collaborators to tasks
 * @signature 9143a511719555e8f8f09b49523bd022
 *
 * This patch renames the %ticket_lock table to just %lock, which allows for
 * it to be considered more flexible. Instead, it joins the lock to the
 * ticket and task objects directly.
 *
 * It also redefines the collaborator table to link to a thread rather than
 * to a ticket, which allows any object in the system with a thread to
 * theoretically have collaborators.
 */

ALTER TABLE `%TABLE_PREFIX%ticket`
  ADD `lock_id` int(11) unsigned NOT NULL default '0' AFTER `email_id`;

RENAME TABLE `%TABLE_PREFIX%ticket_lock` TO `%TABLE_PREFIX%lock`;
ALTER TABLE `%TABLE_PREFIX%lock`
  DROP COLUMN `ticket_id`,
  ADD `code` varchar(20) AFTER `expire`;

-- Drop all the current locks as they do not point to anything now
TRUNCATE TABLE `%TABLE_PREFIX%lock`;

RENAME TABLE `%TABLE_PREFIX%ticket_collaborator` TO `%TABLE_PREFIX%thread_collaborator`;
ALTER TABLE `%TABLE_PREFIX%thread_collaborator`
  CHANGE `ticket_id` `thread_id` int(11) unsigned NOT NULL DEFAULT '0';

UPDATE `%TABLE_PREFIX%thread_collaborator` t1
    LEFT JOIN  `%TABLE_PREFIX%thread` t2 ON (t2.object_id = t1.thread_id  and t2.object_type = 'T')
    SET t1.thread_id = t2.id, t1.created = t2.created;

-- Drop zombie collaborators from tickets which were deleted and had
-- collaborators and the collaborators were not removed
DELETE A1.*
    FROM `%TABLE_PREFIX%thread_collaborator` A1
    LEFT JOIN `%TABLE_PREFIX%thread` A2 ON (A2.id = A1.thread_id)
    WHERE A2.id IS NULL;

ALTER TABLE `%TABLE_PREFIX%task`
  ADD `lock_id` int(11) unsigned NOT NULL DEFAULT '0' AFTER `team_id`;

ALTER TABLE `%TABLE_PREFIX%thread_entry`
  ADD `flags` int(11) unsigned NOT NULL default '0' AFTER `type`;

-- Set the ORIGINAL_MESSAGE flag to all the first messages of each thread
CREATE TABLE `%TABLE_PREFIX%_orig_msg_ids`
  (id INT NOT NULL, PRIMARY KEY (id))
  SELECT min(id) as id FROM `%TABLE_PREFIX%thread_entry`
  WHERE type = 'M'
  GROUP BY thread_id;

UPDATE `%TABLE_PREFIX%thread_entry` A1
  JOIN `%TABLE_PREFIX%_orig_msg_ids` A2 ON (A1.id = A2.id)
  SET A1.`flags` = 1 ;

DROP TABLE `%TABLE_PREFIX%_orig_msg_ids`;

-- Finished with patch
UPDATE `%TABLE_PREFIX%config`
    SET `value` = '9143a511719555e8f8f09b49523bd022'
    WHERE `key` = 'schema_signature' AND `namespace` = 'core';
