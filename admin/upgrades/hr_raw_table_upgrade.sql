-- HEALTH_HR_RAW: one record per raw heart-rate sample, unifying both
-- Samsung sources that carry HR ("traces") into a single clean timeline -
-- tracker.heart_rate's background binning_data (source='background') and
-- the exercise export's own per-session live_data (source='exercise').
--
-- Built because PULSE's background source alone has a real gap during an
-- active exercise session (confirmed 2025-03-17: background slots
-- 00:00-06:30 then nothing until 22:30, exercise 12:06-13:28 sitting
-- squarely in the hole) - the exercise live_data covers exactly that
-- window but only when the exercise-tracking app was started. Neither
-- source alone was enough for RAISEDHR (see ImportRaisedHR.php's own
-- docblock for the full incident) - this table exists so RAISEDHR (and any
-- future HR-derived feature) becomes a SQL query over one merged timeline
-- instead of every feature re-parsing and re-merging both raw JSON sources
-- from scratch independently.
--
-- The two sources are genuinely different shapes, not just two copies of
-- the same thing: background bins are a ~59s *span* with min/avg/max
-- (`{heart_rate, heart_rate_max, heart_rate_min, start_time, end_time}`),
-- exercise samples are a bare *instant* (`{heart_rate, start_time}` only,
-- no end, no min/max) at ~1s resolution. END_TIME/HEART_RATE_MIN/
-- HEART_RATE_MAX are nullable and only ever populated for background rows.
--
-- No surrogate ID - START_TIME is the real natural key (background bins
-- are 60s apart, exercise samples ~1s apart, and background is silent
-- exactly when exercise is active per the gap above, so cross-source
-- collision at the same instant isn't a real risk in practice). LOCAL_DATE
-- deliberately not stored - it's derivable from START_TIME, not separate
-- fact worth persisting.
--
-- Deliberately a real table, not a liberty_xref item - ~2.5 million raw
-- samples (233k background + 2.25M exercise, full history) is a different
-- order of magnitude from every other health item, which are all a handful
-- of readings/day (see MANUAL.md's "jsons/ shape taxonomy" section).

CREATE TABLE HEALTH_HR_RAW
(
  START_TIME      Timestamp NOT NULL,
  END_TIME         Timestamp,             -- populated for 'background' rows only
  HEART_RATE       Double Precision NOT NULL,
  HEART_RATE_MIN   Double Precision,       -- populated for 'background' rows only
  HEART_RATE_MAX   Double Precision,       -- populated for 'background' rows only
  SOURCE           Varchar(20) NOT NULL,   -- 'background' | 'exercise'
  DATAUUID         Varchar(64),            -- originating CSV row's datauuid, for traceability
  PRIMARY KEY (START_TIME)
);

COMMIT;

GRANT DELETE, INSERT, REFERENCES, SELECT, UPDATE
 ON HEALTH_HR_RAW TO SYSDBA WITH GRANT OPTION;

COMMIT;
