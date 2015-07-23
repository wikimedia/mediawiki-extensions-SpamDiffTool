<?php
/**
 * SpamDiffTool extension -- provides a basic way of adding new entries to the
 * Spam Blacklist from diff pages
 *
 * @file
 * @ingroup Extensions
 * @author Travis Derouin <travis@wikihow.com>
 * @author Alexandre Emsenhuber
 * @author Jack Phoenix <jack@countervandalism.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link https://www.mediawiki.org/wiki/Extension:SpamDiffTool Documentation
 */

$wgSpamBlacklistArticle = 'Project:Spam_Blacklist';

$wgExtensionCredits['antispam'][] = array(
	'path' => __FILE__,
	'name' => 'SpamDiffTool',
	'version' => '1.1',
	'license-name' => 'GPL-2.0+',
	'author' => array( 'Travis Derouin', 'Alexandre Emsenhuber', 'Jack Phoenix' ),
	'description' => 'Provides a basic way of adding new entries to the Spam Blacklist from diff pages',
	'url' => 'https://www.mediawiki.org/wiki/Extension:SpamDiffTool',
);

$wgResourceModules['ext.spamdifftool.styles'] = array(
	'styles' => 'ext.spamdifftool.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'SpamDiffTool',
	'position' => 'top'
);

$wgMessagesDirs['SpamDiffTool'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['SpamDiffToolAlias'] = __DIR__ . '/SpamDiffTool.alias.php';

$wgSpecialPages['SpamDiffTool'] = 'SpamDiffTool';
$wgAutoloadClasses['SpamDiffTool'] = __DIR__ . '/SpamDiffTool.body.php';
$wgAutoloadClasses['SpamDiffToolHooks'] = __DIR__ . '/SpamDiffToolHooks.php';
$wgHooks['DiffViewHeader'][] = 'SpamDiffToolHooks::onDiffViewHeader';