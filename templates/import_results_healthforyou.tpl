{strip}
<div class="display health">
	<div class="header">
		<h1>{$page_title|default:"Upload HealthForYou Export"|escape}</h1>
	</div>
	<div class="body">
		{if $uploadForm}
			<form method="post" enctype="multipart/form-data">
				<div class="form-inline">
					<input type="file" name="export_file" accept=".csv,text/csv" />
					<input type="submit" class="btn btn-default btn-sm" value="{tr}Upload &amp; Run{/tr}" />
				</div>
			</form>
			<hr />
		{/if}

		{if $csvFile}
			<p>{tr}File{/tr}: <code>{$csvFile|escape}</code></p>
		{/if}

		{if $sections}
			<table class="table table-condensed">
				<thead><tr><th>{tr}Section{/tr}</th><th>{tr}Created{/tr}</th><th>{tr}Skipped{/tr}</th></tr></thead>
				<tbody>
					{foreach $sections as $title => $r}
					<tr>
						<td>{$title|escape}</td>
						<td>{$r.created}</td>
						<td>{$r.skipped}</td>
					</tr>
					{/foreach}
				</tbody>
			</table>

			{foreach $sections as $title => $r}
				{if $r.errors}
					<h3>{$title|escape} {tr}errors{/tr}</h3>
					<ul>
						{foreach $r.errors as $msg}
							<li>{$msg|escape}</li>
						{/foreach}
					</ul>
				{/if}
			{/foreach}
		{/if}

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
