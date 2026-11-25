-- The eight MySQL accounts of the cutover (design v2, section 2.2). Run once as MySQL root.
--
-- Passwords never appear in this file. Each `:name` placeholder is filled from a root-only file
-- by `fixture.sh render accounts.sql <password-dir>` (one file per placeholder, mode 0600), and
-- the local test fixture is built from this same file the same way.
--
-- Requires the control table first (runbook Release step 4, as root, without its generic grant):
-- repro_mysql_control.writer_token must exist, or the table grants below fail.
--
-- Every schema-level grant escapes the underscore: an unescaped `_` is a wildcard.
-- The write gate: repro_app has SELECT only until seal_mysql grants INSERT, UPDATE, DELETE on the
-- app schema and UPDATE (`id`) on the token; the probe reads that state, the gate account changes it.

CREATE USER 'repro_app'@'localhost' IDENTIFIED BY :app;
GRANT SELECT ON `repro\_dashboard`.* TO 'repro_app'@'localhost';
GRANT SELECT ON `repro_mysql_control`.`writer_token` TO 'repro_app'@'localhost';

CREATE USER 'repro_maint'@'localhost' IDENTIFIED BY :maint;
GRANT ALL ON `repro\_dashboard`.* TO 'repro_maint'@'localhost';
GRANT SESSION_VARIABLES_ADMIN ON *.* TO 'repro_maint'@'localhost';

CREATE USER 'repro_migrator'@'localhost' IDENTIFIED BY :migrator;
GRANT ALL ON `repro\_dashboard`.* TO 'repro_migrator'@'localhost';
GRANT SELECT, UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_migrator'@'localhost';

CREATE USER 'repro_parity_reader'@'localhost' IDENTIFIED BY :reader;
GRANT SELECT ON `repro\_dashboard`.* TO 'repro_parity_reader'@'localhost';

CREATE USER 'repro_token_check'@'localhost' IDENTIFIED BY :check;
GRANT SELECT ON `repro\_dashboard`.* TO 'repro_token_check'@'localhost';
GRANT SELECT, UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_token_check'@'localhost';

CREATE USER 'repro_cutover_probe'@'localhost' IDENTIFIED BY :probe;
GRANT SELECT ON mysql.db TO 'repro_cutover_probe'@'localhost';
GRANT SELECT ON mysql.tables_priv TO 'repro_cutover_probe'@'localhost';
GRANT SELECT ON mysql.columns_priv TO 'repro_cutover_probe'@'localhost';
GRANT SELECT ON mysql.procs_priv TO 'repro_cutover_probe'@'localhost';
GRANT SELECT ON mysql.proxies_priv TO 'repro_cutover_probe'@'localhost';
GRANT SELECT ON mysql.role_edges TO 'repro_cutover_probe'@'localhost';
GRANT SELECT ON mysql.default_roles TO 'repro_cutover_probe'@'localhost';
GRANT SELECT ON mysql.global_grants TO 'repro_cutover_probe'@'localhost';
GRANT SELECT (Host, User, account_locked, Select_priv, Insert_priv, Update_priv, Delete_priv,
  Create_priv, Drop_priv, Reload_priv, Shutdown_priv, Process_priv, File_priv, Grant_priv,
  References_priv, Index_priv, Alter_priv, Show_db_priv, Super_priv, Create_tmp_table_priv,
  Lock_tables_priv, Execute_priv, Repl_slave_priv, Repl_client_priv, Create_view_priv,
  Show_view_priv, Create_routine_priv, Alter_routine_priv, Create_user_priv, Event_priv,
  Trigger_priv, Create_tablespace_priv, Create_role_priv, Drop_role_priv)
  ON mysql.user TO 'repro_cutover_probe'@'localhost';
GRANT REPLICATION SLAVE, REPLICATION CLIENT, PROCESS ON *.* TO 'repro_cutover_probe'@'localhost';

CREATE USER 'repro_cutover_gate'@'localhost' IDENTIFIED BY :gate;
GRANT INSERT, UPDATE, DELETE ON `repro\_dashboard`.* TO 'repro_cutover_gate'@'localhost' WITH GRANT OPTION;
GRANT UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO 'repro_cutover_gate'@'localhost' WITH GRANT OPTION;
GRANT CONNECTION_ADMIN, PROCESS ON *.* TO 'repro_cutover_gate'@'localhost';

CREATE USER 'repro_monitor'@'localhost' IDENTIFIED BY :monitor;
GRANT PROCESS ON *.* TO 'repro_monitor'@'localhost';
GRANT SELECT ON performance_schema.data_locks TO 'repro_monitor'@'localhost';
