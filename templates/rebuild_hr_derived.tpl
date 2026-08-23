{strip}
<div class="display health">
	<div class="header">
		<h1>{tr}Rebuild PULSE + RAISEDHR{/tr}</h1>
	</div>
	<div class="body">
		{if $result.firstDate}
			<p>{tr}Date range{/tr}: <code>{$result.firstDate|escape}</code> {tr}to{/tr} <code>{$result.lastDate|escape}</code></p>
			<p>
				<strong>{$result.daysProcessed}</strong> {tr}days processed{/tr}.
				<strong>{$result.totalPulseSlots}</strong> {tr}PULSE slots created{/tr}.
				<strong>{$result.totalRaisedHr}</strong> {tr}RAISEDHR days written{/tr}.
			</p>
		{else}
			<p>{tr}No data in HEALTH_HR_RAW to rebuild from.{/tr}</p>
		{/if}
	</div>
</div>
{/strip}
