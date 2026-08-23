<?php
/**
 * @package health
 */

global $gBitInstaller;

$gBitInstaller->registerPackageUpgrade(
	[
		'package'     => HEALTH_PKG_NAME,
		'version'     => '5.0.2',
		'description' => 'Add health_hr_raw: one record per raw heart-rate sample, unifying both '
			.'Samsung sources that carry HR (tracker.heart_rate\'s background binning_data and the '
			.'exercise export\'s own live_data) into a single clean timeline. Built because PULSE\'s '
			.'background source alone goes quiet during an active exercise session - the exercise '
			.'export covers exactly that window, but only when the app was started. Deliberately a '
			.'real table, not a liberty_xref item - a different order of magnitude (millions of raw '
			.'samples, full history) from every other health item. No surrogate ID - START_TIME is '
			.'the real natural key (background bins are 60s apart, exercise samples ~1s apart, and '
			.'background is silent exactly when exercise is active, so cross-source collision at the '
			.'same instant is not a real risk in practice).',
	],
	[
		[ 'DATADICT' => [
			[ 'CREATE' => [
				'health_hr_raw' => "
					start_time T PRIMARY,
					end_time T,
					heart_rate F NOTNULL,
					heart_rate_min F,
					heart_rate_max F,
					source C(20) NOTNULL,
					datauuid C(64)
				",
			]],
		]],
	]
);
