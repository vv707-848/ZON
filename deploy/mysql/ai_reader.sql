-- The SuperAgent's read-only user (REPORT_DB_* in backend/.env). Run once as root on the data node, after the
-- migrations (the views come from 2026_11_02_000600_create_report_views.php). It sees the ai_v_* views and
-- nothing else: no table, no name, no phone, no free text of a person.
CREATE USER IF NOT EXISTS 'ai_reader'@'10.10.0.10' IDENTIFIED BY 'CHANGE-ME-long-random';
GRANT SELECT ON zone.ai_v_listings         TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_listing_history  TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_requests         TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_projects         TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_project_units    TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_customers        TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_subscriptions    TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_payments         TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_search_misses    TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_search_feedback  TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_ai_requests      TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_daily_area_stats TO 'ai_reader'@'10.10.0.10';
GRANT SELECT ON zone.ai_v_preferences      TO 'ai_reader'@'10.10.0.10';
-- Every query of this user is cut after 5 seconds.
ALTER USER 'ai_reader'@'10.10.0.10' WITH MAX_QUERIES_PER_HOUR 2000;
FLUSH PRIVILEGES;
