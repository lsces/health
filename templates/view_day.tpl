{strip}
<div class="display health">
	<div class="header">
		<h1>{tr}Day{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{if $groups}
			{foreach $groups as $item => $g}
				<h3>{$g.title|escape}</h3>
				<table class="table table-condensed">
					<thead>
						<tr>
							<th>{tr}When{/tr}</th>
							<th>{$g.xkeyTitle|escape}</th>
							<th>{$g.xkeyExtTitle|escape}</th>
							<th>{$g.dataTitle|escape}</th>
						</tr>
					</thead>
					<tbody>
						{foreach $g.rows as $r}
						<tr>
							<td>{$r.start_date|escape}</td>
							<td>{$r.xkey|escape}</td>
							<td>{$r.xkey_ext|escape}</td>
							<td>
								{if $r.data_summary}
									<details><summary>{$r.data_summary|escape}</summary><pre>{$r.data|escape}</pre></details>
								{else}
									{$r.data|escape}
								{/if}
							</td>
						</tr>
						{/foreach}
					</tbody>
				</table>
			{/foreach}
		{else}
			<p>{tr}Nothing logged for this day.{/tr}</p>
		{/if}
	</div>
</div>
{/strip}
