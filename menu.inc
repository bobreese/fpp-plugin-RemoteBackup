<?php

# $menuEntries is an array of menu entries for this plugin
$menuEntries = Array(
	Array(
		'text' => 'Remote Backup - Status',
		'type' => 'status',
		'page' => 'status.php',
		'wrap' => 1
	),
	Array(
		'text' => 'Remote Backup - Config',
		'type' => 'content',
		'page' => 'config.php',
		'wrap' => 1
	),
	Array(
		'text' => 'Remote Backup - Help',
		'type' => 'help',
		'page' => 'help/help.php',
		'wrap' => 1
	)
);

##############################################################################
# Display the menu entries for this plugin.
# Expects $plugin and $menu to already be set by plugin.php
foreach ($menuEntries as $entry)
{
	if ($entry['type'] != $menu)
		continue;

	if (preg_match('/^http.?:\/\//', $entry['page']))
	{
		printf("<li><a href='%s' target='_blank'>%s</a></li>\n",
			$entry['page'], $entry['text']);
	}
	else
	{
		$nopage = '';
		if (isset($entry['wrap']) && ($entry['wrap'] == 0))
			$nopage = '&nopage=1';

		printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
			$plugin, $entry['page'], $nopage, $entry['text']);
	}
}
?>
