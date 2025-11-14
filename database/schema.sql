-- ----------------------------------------------------------------
-- Jira to Redmine Migration Staging Schema
-- ----------------------------------------------------------------
-- This schema is designed to facilitate a robust, stateful, and
-- idempotent ETL process for migrating data from Jira to Redmine.
-- Each entity has three corresponding tables:
-- 1. `staging_jira_*`: A raw, immutable copy of data from the Jira API.
-- 2. `staging_redmine_*`: A snapshot of existing data in the Redmine instance.
-- 3. `migration_mapping_*`: The core state machine and mapping table.
-- ----------------------------------------------------------------

-- ================================================================
-- Table Set 1: Users
-- ================================================================
CREATE TABLE `staging_jira_users` (
                                      `account_id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                      `account_type` VARCHAR(100) NULL,
                                      `display_name` VARCHAR(255) NOT NULL,
                                      `email_address` VARCHAR(255) NULL,
                                      `is_active` BOOLEAN NOT NULL,
                                      `group_memberships` JSON NULL,
                                      `raw_payload` JSON NOT NULL,
                                      `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Raw extraction of Jira Users.';

CREATE TABLE `staging_redmine_users` (
                                         `id` INT NOT NULL PRIMARY KEY,
                                         `login` VARCHAR(255) NOT NULL,
                                         `firstname` VARCHAR(255),
                                         `lastname` VARCHAR(255),
                                         `mail` VARCHAR(255) NOT NULL,
                                         `status` INT NOT NULL,
                                         `raw_payload` JSON NOT NULL,
                                         `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                         UNIQUE KEY `uk_redmine_user_login` (`login`),
                                         UNIQUE KEY `uk_redmine_user_mail` (`mail`)
) COMMENT='Snapshot of existing Redmine Users.';

CREATE TABLE `migration_mapping_users` (
                                           `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                           `jira_account_id` VARCHAR(255) NOT NULL,
                                           `jira_display_name` VARCHAR(255) NULL,
                                           `jira_email_address` VARCHAR(255) NULL,
                                           `redmine_user_id` INT NULL,
                                           `match_type` ENUM('NONE', 'LOGIN', 'MAIL', 'MANUAL') NOT NULL DEFAULT 'NONE',
                                           `proposed_redmine_login` VARCHAR(255) NULL,
                                           `proposed_redmine_mail` VARCHAR(255) NULL,
                                           `proposed_firstname` VARCHAR(255) NULL,
                                           `proposed_lastname` VARCHAR(255) NULL,
                                           `proposed_redmine_status` ENUM('ACTIVE', 'LOCKED') NOT NULL DEFAULT 'LOCKED',
                                           `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                           `notes` TEXT,
                                           `automation_hash` CHAR(64) NULL,
                                           `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                           `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                           UNIQUE KEY `uk_jira_account_id` (`jira_account_id`)
) COMMENT='Mapping and status for User migration.';

-- ================================================================
-- Table Set 2: Groups
-- ================================================================
CREATE TABLE `staging_jira_groups` (
                                       `group_id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                       `name` VARCHAR(255) NOT NULL,
                                       `raw_payload` JSON NOT NULL,
                                       `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Raw extraction of Jira Groups.';

CREATE TABLE `staging_jira_group_members` (
                                             `group_id` VARCHAR(255) NOT NULL,
                                             `account_id` VARCHAR(255) NOT NULL,
                                             `raw_payload` JSON NOT NULL,
                                             `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                             PRIMARY KEY (`group_id`, `account_id`),
                                             KEY `idx_jira_group_members_account` (`account_id`)
) COMMENT='Raw extraction of Jira group memberships.';

CREATE TABLE `staging_redmine_groups` (
                                          `id` INT NOT NULL PRIMARY KEY,
                                          `name` VARCHAR(255) NOT NULL,
                                          `raw_payload` JSON NOT NULL,
                                          `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                          UNIQUE KEY `uk_redmine_group_name` (`name`)
) COMMENT='Snapshot of existing Redmine Groups.';

CREATE TABLE `staging_redmine_group_members` (
                                                `group_id` INT NOT NULL,
                                                `user_id` INT NOT NULL,
                                                `raw_payload` JSON NOT NULL,
                                                `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                PRIMARY KEY (`group_id`, `user_id`),
                                                KEY `idx_redmine_group_members_user` (`user_id`)
) COMMENT='Snapshot of Redmine group memberships.';

CREATE TABLE `staging_redmine_group_project_roles` (
                                                      `group_id` INT NOT NULL,
                                                      `membership_id` INT NOT NULL,
                                                      `project_id` INT NOT NULL,
                                                      `project_name` VARCHAR(255) NULL,
                                                      `role_id` INT NOT NULL,
                                                      `role_name` VARCHAR(255) NULL,
                                                      `raw_payload` JSON NOT NULL,
                                                      `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                      PRIMARY KEY (`group_id`, `membership_id`, `role_id`),
                                                      KEY `idx_redmine_group_project_roles_project` (`project_id`),
                                                      KEY `idx_redmine_group_project_roles_role` (`role_id`)
) COMMENT='Snapshot of Redmine group project role memberships.';

CREATE TABLE `migration_mapping_groups` (
        `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
        `jira_group_id` VARCHAR(255) NOT NULL,
        `jira_group_name` VARCHAR(255) NULL,
        `redmine_group_id` INT NULL,
        `proposed_redmine_name` VARCHAR(255) NULL,
        `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
        `notes` TEXT,
        `automation_hash` CHAR(64) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_jira_group_id` (`jira_group_id`)
) COMMENT='Mapping and status for Group migration.';

CREATE TABLE `migration_mapping_group_members` (
                                                  `member_mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                                  `jira_group_id` VARCHAR(255) NOT NULL,
                                                  `jira_group_name` VARCHAR(255) NULL,
                                                  `jira_account_id` VARCHAR(255) NOT NULL,
                                                  `redmine_group_id` INT NULL,
                                                  `redmine_user_id` INT NULL,
                                                  `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_ASSIGNMENT', 'AWAITING_GROUP', 'AWAITING_USER', 'ASSIGNMENT_SUCCESS', 'ASSIGNMENT_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                                  `notes` TEXT,
                                                  `automation_hash` CHAR(64) NULL,
                                                  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                  `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                  UNIQUE KEY `uk_jira_group_member` (`jira_group_id`, `jira_account_id`)
) COMMENT='Mapping and status for synchronising group memberships.';

-- ================================================================
-- Table Set 3: Roles
-- ================================================================
CREATE TABLE `staging_jira_project_roles` (
                                              `id` BIGINT NOT NULL PRIMARY KEY,
                                              `name` VARCHAR(255) NOT NULL,
                                              `description` TEXT,
                                              `raw_payload` JSON NOT NULL,
                                              `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Raw extraction of Jira Project Roles.';

CREATE TABLE `staging_jira_project_role_actors` (
                                                   `project_id` VARCHAR(255) NOT NULL,
                                                   `project_key` VARCHAR(255) NULL,
                                                   `project_name` VARCHAR(255) NULL,
                                                   `role_id` BIGINT NOT NULL,
                                                   `role_name` VARCHAR(255) NULL,
                                                   `actor_id` VARCHAR(255) NOT NULL,
                                                   `actor_display` VARCHAR(255) NULL,
                                                   `actor_type` ENUM('atlassian-group-role-actor', 'atlassian-user-role-actor') NOT NULL,
                                                   `raw_payload` JSON NOT NULL,
                                                   `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                   PRIMARY KEY (`project_id`, `role_id`, `actor_id`, `actor_type`),
                                                   KEY `idx_jira_role_actor_project_role` (`project_id`, `role_id`),
                                                   KEY `idx_jira_role_actor_actor` (`actor_id`)
) COMMENT='Raw extraction of Jira project role actors (users and groups).';

CREATE TABLE `staging_redmine_roles` (
                                         `id` INT NOT NULL PRIMARY KEY,
                                         `name` VARCHAR(255) NOT NULL,
                                         `assignable` BOOLEAN,
                                         `raw_payload` JSON NOT NULL,
                                         `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Snapshot of existing Redmine Roles.';

CREATE TABLE `migration_mapping_roles` (
                                           `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                           `jira_role_id` BIGINT NOT NULL,
                                           `jira_role_name` VARCHAR(255) NULL,
                                           `jira_role_description` TEXT NULL,
                                           `redmine_role_id` INT NULL,
                                           `proposed_redmine_role_id` INT NULL,
                                           `proposed_redmine_role_name` VARCHAR(255) NULL,
                                           `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                           `notes` TEXT,
                                           `automation_hash` CHAR(64) NULL,
                                           `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                           `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                           UNIQUE KEY `uk_jira_role_id` (`jira_role_id`)
) COMMENT='Mapping and status for Role migration.';

CREATE TABLE `migration_mapping_project_role_groups` (
                                                        `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                                        `jira_project_id` VARCHAR(255) NOT NULL,
                                                        `jira_project_key` VARCHAR(255) NULL,
                                                        `jira_project_name` VARCHAR(255) NULL,
                                                        `jira_role_id` BIGINT NOT NULL,
                                                        `jira_role_name` VARCHAR(255) NULL,
                                                        `jira_group_id` VARCHAR(255) NOT NULL,
                                                        `jira_group_name` VARCHAR(255) NULL,
                                                        `redmine_project_id` INT NULL,
                                                        `redmine_group_id` INT NULL,
                                                        `redmine_role_id` INT NULL,
                                                        `proposed_redmine_role_id` INT NULL,
                                                        `proposed_redmine_role_name` VARCHAR(255) NULL,
                                                        `migration_status` ENUM('PENDING_ANALYSIS', 'READY_FOR_ASSIGNMENT', 'ASSIGNMENT_RECORDED', 'AWAITING_PROJECT', 'AWAITING_GROUP', 'AWAITING_ROLE', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                                        `notes` TEXT,
                                                        `automation_hash` CHAR(64) NULL,
                                                        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                        `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                        UNIQUE KEY `uk_project_role_group` (`jira_project_id`, `jira_role_id`, `jira_group_id`)
) COMMENT='Mapping Jira project role group assignments to Redmine projects/groups/roles.';

-- ================================================================
-- Table Set 4: Issue Statuses
-- ================================================================
CREATE TABLE `staging_jira_statuses` (
                                         `id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                         `name` VARCHAR(255) NOT NULL,
                                         `description` TEXT,
                                         `status_category_key` VARCHAR(100),
                                         `raw_payload` JSON NOT NULL,
                                         `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Raw extraction of Jira Issue Statuses.';

CREATE TABLE `staging_redmine_issue_statuses` (
                                                  `id` INT NOT NULL PRIMARY KEY,
                                                  `name` VARCHAR(255) NOT NULL,
                                                  `is_closed` BOOLEAN NOT NULL,
                                                  `raw_payload` JSON NOT NULL,
                                                  `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Snapshot of existing Redmine Issue Statuses.';

CREATE TABLE `migration_mapping_statuses` (
                                              `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                              `jira_status_id` VARCHAR(255) NOT NULL,
                                              `jira_status_name` VARCHAR(255) NULL,
                                              `jira_status_category_key` VARCHAR(100) NULL,
                                              `redmine_status_id` INT NULL,
                                              `proposed_redmine_name` VARCHAR(255) NULL,
                                              `proposed_is_closed` BOOLEAN NULL,
                                              `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                              `notes` TEXT,
                                              `automation_hash` CHAR(64) NULL,
                                              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                              `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                              UNIQUE KEY `uk_jira_status_id` (`jira_status_id`)
) COMMENT='Mapping and status for Issue Status migration.';

-- ================================================================
-- Table Set 5: Issue Priorities
-- ================================================================
CREATE TABLE `staging_jira_priorities` (
                                           `id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                           `name` VARCHAR(255) NOT NULL,
                                           `description` TEXT,
                                           `raw_payload` JSON NOT NULL,
                                           `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Raw extraction of Jira Issue Priorities.';

CREATE TABLE `staging_redmine_issue_priorities` (
                                                    `id` INT NOT NULL PRIMARY KEY,
                                                    `name` VARCHAR(255) NOT NULL,
                                                    `is_default` BOOLEAN NOT NULL,
                                                    `position` INT NULL,
                                                    `raw_payload` JSON NOT NULL,
                                                    `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Snapshot of existing Redmine Issue Priorities.';

CREATE TABLE `migration_mapping_priorities` (
                                                `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                                `jira_priority_id` VARCHAR(255) NOT NULL,
                                                `jira_priority_name` VARCHAR(255) NULL,
                                                `jira_priority_description` TEXT NULL,
                                                `redmine_priority_id` INT NULL,
                                                `proposed_redmine_name` VARCHAR(255) NULL,
                                                `proposed_is_default` BOOLEAN NULL,
                                                `proposed_redmine_position` INT NULL,
                                                `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                                `notes` TEXT,
                                                `automation_hash` CHAR(64) NULL,
                                                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                UNIQUE KEY `uk_jira_priority_id` (`jira_priority_id`)
) COMMENT='Mapping and status for Issue Priority migration.';

-- ================================================================
-- Table Set 6: Trackers (Jira Issue Types)
-- ================================================================
CREATE TABLE `staging_jira_issue_types` (
                                            `id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                            `name` VARCHAR(255) NOT NULL,
                                            `description` TEXT,
                                            `is_subtask` BOOLEAN NOT NULL,
                                            `hierarchy_level` INT,
                                            `scope_type` VARCHAR(50),
                                            `scope_project_id` VARCHAR(255),
                                            `raw_payload` JSON NOT NULL,
                                            `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Raw extraction of Jira Issue Types.';

CREATE TABLE `staging_redmine_trackers` (
                                            `id` INT NOT NULL PRIMARY KEY,
                                            `name` VARCHAR(255) NOT NULL,
                                            `description` TEXT,
                                            `default_status_id` INT,
                                            `raw_payload` JSON NOT NULL,
                                            `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Snapshot of existing Redmine Trackers.';

CREATE TABLE `migration_mapping_trackers` (
                                              `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                              `jira_issue_type_id` VARCHAR(255) NOT NULL,
                                              `jira_issue_type_name` VARCHAR(255) NULL,
                                              `jira_issue_type_description` TEXT NULL,
                                              `jira_is_subtask` BOOLEAN NULL,
                                              `jira_hierarchy_level` INT NULL,
                                              `jira_scope_type` VARCHAR(50) NULL,
                                              `jira_scope_project_id` VARCHAR(255) NULL,
                                              `redmine_tracker_id` INT NULL,
                                              `proposed_redmine_name` VARCHAR(255) NULL,
                                              `proposed_redmine_description` TEXT NULL,
                                              `proposed_default_status_id` INT NULL,
                                              `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                              `notes` TEXT,
                                              `automation_hash` CHAR(64) NULL,
                                              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                              `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                              UNIQUE KEY `uk_jira_issue_type_id` (`jira_issue_type_id`)
) COMMENT='Mapping and status for Tracker migration.';

-- ================================================================
-- Table Set 7: Custom Fields
-- ================================================================
CREATE TABLE `staging_jira_fields` (
                                       `id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                       `name` VARCHAR(255) NOT NULL,
                                       `is_custom` BOOLEAN NOT NULL,
                                       `schema_type` VARCHAR(255),
                                       `raw_payload` JSON NOT NULL,
                                       `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Raw extraction of Jira Fields (system and custom).';

CREATE TABLE `staging_redmine_custom_fields` (
                                                 `id` INT NOT NULL PRIMARY KEY,
                                                 `name` VARCHAR(255) NOT NULL,
                                                 `field_format` VARCHAR(255) NOT NULL,
                                                 `is_for_all` BOOLEAN,
                                                 `raw_payload` JSON NOT NULL,
                                                 `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Snapshot of existing Redmine Custom Fields.';

CREATE TABLE `migration_mapping_custom_fields` (
                                                   `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                                   `jira_field_id` VARCHAR(255) NOT NULL,
                                                   `redmine_custom_field_id` INT NULL,
                                                   `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                                   `notes` TEXT,
                                                   `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                   `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                   UNIQUE KEY `uk_jira_field_id` (`jira_field_id`)
) COMMENT='Mapping and status for Custom Field migration.';

-- ================================================================
-- Table Set 8: Projects
-- ================================================================
CREATE TABLE `staging_jira_projects` (
                                         `id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                         `project_key` VARCHAR(255) NOT NULL,
                                         `name` VARCHAR(255) NOT NULL,
                                         `description` TEXT,
                                         `is_private` BOOLEAN,
                                         `lead_account_id` VARCHAR(255),
                                         `raw_payload` JSON NOT NULL,
                                         `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                         UNIQUE KEY `uk_jira_project_key` (`project_key`)
) COMMENT='Raw extraction of Jira Projects.';

CREATE TABLE `staging_redmine_projects` (
                                            `id` INT NOT NULL PRIMARY KEY,
                                            `name` VARCHAR(255) NOT NULL,
                                            `identifier` VARCHAR(255) NOT NULL,
                                            `description` TEXT,
                                            `is_public` BOOLEAN,
                                            `parent_id` INT NULL,
                                            `raw_payload` JSON NOT NULL,
                                            `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                            UNIQUE KEY `uk_redmine_project_identifier` (`identifier`)
) COMMENT='Snapshot of existing Redmine Projects.';

CREATE TABLE `migration_mapping_projects` (
                                              `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                              `jira_project_id` VARCHAR(255) NOT NULL,
                                              `redmine_project_id` INT NULL,
                                              `proposed_identifier` VARCHAR(255) NULL,
                                              `proposed_name` VARCHAR(255) NULL,
                                              `proposed_description` TEXT NULL,
                                              `proposed_is_public` BOOLEAN NULL,
                                              `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                              `notes` TEXT,
                                              `automation_hash` CHAR(64) NULL,
                                              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                              `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                              UNIQUE KEY `uk_jira_project_id` (`jira_project_id`)
) COMMENT='Mapping and status for Project migration.';

-- ================================================================
-- Table Set 9: Tags (from Jira Labels)
-- Assumes a standard Redmine tags plugin schema (`tags` and `taggings`).
-- ================================================================
CREATE TABLE `staging_jira_labels` (
                                       `label_name` VARCHAR(255) NOT NULL PRIMARY KEY
) COMMENT='Unique list of all labels extracted from all Jira issues.';

CREATE TABLE `staging_redmine_tags` (
                                        `id` INT NOT NULL PRIMARY KEY,
                                        `name` VARCHAR(255) NOT NULL,
                                        `retrieved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        UNIQUE KEY `uk_redmine_tag_name` (`name`)
) COMMENT='Snapshot of existing Redmine Tags.';

CREATE TABLE `migration_mapping_tags` (
                                          `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                          `jira_label_name` VARCHAR(255) NOT NULL,
                                          `redmine_tag_id` INT NULL,
                                          `migration_status` ENUM('PENDING_ANALYSIS', 'MATCH_FOUND', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                          `notes` TEXT,
                                          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                          `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                          UNIQUE KEY `uk_jira_label_name` (`jira_label_name`)
) COMMENT='Mapping and status for Tag migration.';

-- ================================================================
-- Table Set 10: Issues
-- This is the central data entity.
-- ================================================================
CREATE TABLE `staging_jira_issues` (
                                       `id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                       `issue_key` VARCHAR(255) NOT NULL,
                                       `summary` VARCHAR(255) NOT NULL,
                                       `description_adf` JSON,
                                       `project_id` VARCHAR(255) NOT NULL,
                                       `issuetype_id` VARCHAR(255) NOT NULL,
                                       `status_id` VARCHAR(255) NOT NULL,
                                       `priority_id` VARCHAR(255),
                                       `reporter_account_id` VARCHAR(255),
                                       `assignee_account_id` VARCHAR(255),
                                       `parent_issue_id` VARCHAR(255) NULL,
                                       `created_at` DATETIME NOT NULL,
                                       `updated_at` DATETIME NOT NULL,
                                       `raw_payload` JSON NOT NULL,
                                       `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                       UNIQUE KEY `uk_jira_issue_key` (`issue_key`)
) COMMENT='Raw extraction of Jira Issues.';

CREATE TABLE `migration_mapping_issues` (
                                            `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                            `jira_issue_id` VARCHAR(255) NOT NULL,
                                            `jira_issue_key` VARCHAR(255) NOT NULL,
                                            `redmine_issue_id` INT NULL,
                                            `migration_status` ENUM('PENDING_ANALYSIS', 'READY_FOR_CREATION', 'CREATION_SUCCESS', 'CREATION_FAILED', 'IGNORED') NOT NULL DEFAULT 'PENDING_ANALYSIS',
                                            `notes` TEXT,
                                            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                            `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                            UNIQUE KEY `uk_jira_issue_id` (`jira_issue_id`)
) COMMENT='Mapping and status for Issue migration.';

-- ================================================================
-- Table Set 11: Issue Journals (Comments & History)
-- ================================================================
CREATE TABLE `staging_jira_changelogs` (
                                           `id` VARCHAR(255) NOT NULL,
                                           `issue_id` VARCHAR(255) NOT NULL,
                                           `author_account_id` VARCHAR(255),
                                           `created_at` DATETIME NOT NULL,
                                           `items_json` JSON,
                                           `raw_payload` JSON NOT NULL,
                                           `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                           PRIMARY KEY (`id`, `issue_id`)
) COMMENT='Raw extraction of Jira Issue Changelogs (history).';

CREATE TABLE `staging_jira_comments` (
                                         `id` VARCHAR(255) NOT NULL,
                                         `issue_id` VARCHAR(255) NOT NULL,
                                         `author_account_id` VARCHAR(255),
                                         `body_adf` JSON,
                                         `created_at` DATETIME NOT NULL,
                                         `updated_at` DATETIME NOT NULL,
                                         `raw_payload` JSON NOT NULL,
                                         `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                         PRIMARY KEY (`id`, `issue_id`)
) COMMENT='Raw extraction of Jira Issue Comments.';

CREATE TABLE `migration_mapping_journals` (
                                              `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                              `jira_entity_id` VARCHAR(255) NOT NULL COMMENT 'Can be changelog ID or comment ID',
                                              `jira_issue_id` VARCHAR(255) NOT NULL,
                                              `entity_type` ENUM('COMMENT', 'CHANGELOG') NOT NULL,
                                              `redmine_journal_id` INT NULL,
                                              `migration_status` ENUM('PENDING', 'SUCCESS', 'FAILED', 'IGNORED') NOT NULL DEFAULT 'PENDING',
                                              `notes` TEXT,
                                              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                              `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                              UNIQUE KEY `uk_jira_journal_entity` (`jira_entity_id`, `entity_type`)
) COMMENT='Mapping for issue history items (comments/changelogs).';

-- ================================================================
-- Table Set 12: Attachments
-- ================================================================
CREATE TABLE `staging_jira_attachments` (
                                            `id` VARCHAR(255) NOT NULL PRIMARY KEY,
                                            `issue_id` VARCHAR(255) NOT NULL,
                                            `filename` VARCHAR(255) NOT NULL,
                                            `author_account_id` VARCHAR(255),
                                            `created_at` DATETIME NOT NULL,
                                            `size_bytes` BIGINT NOT NULL,
                                            `mime_type` VARCHAR(255),
                                            `content_url` TEXT NOT NULL,
                                            `raw_payload` JSON NOT NULL,
                                            `extracted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) COMMENT='Raw extraction of Jira Attachment metadata.';

CREATE TABLE `migration_mapping_attachments` (
                                                 `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
                                                 `jira_attachment_id` VARCHAR(255) NOT NULL,
                                                 `jira_issue_id` VARCHAR(255) NOT NULL,
                                                 `redmine_attachment_id` INT NULL,
                                                 `redmine_upload_token` VARCHAR(255) NULL,
                                                 `migration_status` ENUM('PENDING_DOWNLOAD', 'PENDING_UPLOAD', 'PENDING_ASSOCIATION', 'SUCCESS', 'FAILED') NOT NULL DEFAULT 'PENDING_DOWNLOAD',
                                                 `local_filepath` VARCHAR(1024) NULL,
                                                 `notes` TEXT,
                                                 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                 `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                 UNIQUE KEY `uk_jira_attachment_id` (`jira_attachment_id`)
) COMMENT='Mapping and status for Attachment migration.';
