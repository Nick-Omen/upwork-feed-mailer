CREATE TABLE `viewed_jobs` (
  `job_id` VARCHAR(128) NOT NULL DEFAULT "",
  `date`   DATETIME,
  `send` BOOLEAN NOT NULL DEFAULT 0,
  PRIMARY KEY (`job_id`)
);
CREATE INDEX `viewed_jobs_index` ON `viewed_jobs` (job_id(20));
CREATE TABLE `config` (
  `key`   VARCHAR(32) NOT NULL DEFAULT "",
  `value` VARCHAR(64) NOT NULL DEFAULT ""
);
INSERT INTO `config` VALUES
("emails", "nikita.omen666@gmail.com"),
("cron_active", "0"),
("sleep_seconds", "60"),
("latest_update", ""),
("query", "");
