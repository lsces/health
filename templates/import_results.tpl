{strip}
<div class="display health">
	<div class="header">
		<h1>{$page_title|default:"Import Results"|escape}</h1>
	</div>
	<div class="body">
		<p>{tr}File{/tr}: <code>{$csvFile|escape}</code></p>

		<p>
			<strong>{$created}</strong> {tr}created{/tr}.
			<strong>{$skipped}</strong> {tr}skipped{/tr}.
			{if $rowsNoBinning}<strong>{$rowsNoBinning}</strong> {tr}source rows had no minute detail, not counted above{/tr}.{/if}
		</p>

		{if $errors}
			<h3>{tr}Errors{/tr}</h3>
			<ul>
				{foreach $errors as $msg}
					<li>{$msg|escape}</li>
				{/foreach}
			</ul>
		{/if}
	</div>
</div>
{/strip}
