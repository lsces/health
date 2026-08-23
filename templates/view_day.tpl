{strip}
<div class="display health">
	<div class="header">
		<h1>{tr}Day{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{jstabs}
			{jstab title="Summary"}
				{if $singleItems}
					<table class="table table-condensed">
						<tbody>
							{foreach $singleItems as $item => $g}
								{foreach $g.rows as $r}
								<tr>
									<td>{$g.title|escape}</td>
									<td>{$g.xkeyTitle|escape}: {$r.xkey|escape}</td>
									<td>{if $r.xkey_ext !== null && $r.xkey_ext !== ''}{$g.xkeyExtTitle|escape}: {$r.xkey_ext|escape}{/if}</td>
								</tr>
								{/foreach}
							{/foreach}
						</tbody>
					</table>
				{else}
					<p>{tr}Nothing single-value logged for this day.{/tr}</p>
				{/if}
			{/jstab}

			{foreach $multiItems as $item => $g}
				{jstab title="$g.title"}
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
				{/jstab}
			{/foreach}
		{/jstabs}
	</div>
</div>
{/strip}
