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
	'version' => '1.0',
	'license-name' => 'GPLv2+',
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

/**
 * Adds the "add to spam [blacklist]" link to the diff view.
 *
 * @param DifferenceEngine $diffEngine
 * @param Revision $oldRev Revision object for the older revision
 * @param Revision $newRev Revision object for the newer revision
 * @return bool
 */
$wgHooks['DiffViewHeader'][] = function( $diffEngine, $oldRev, $newRev ) {
	global $wgOut, $wgSpamBlacklistArticle;

	$sb = Title::newFromDBKey( $wgSpamBlacklistArticle );
	if ( !$sb->userCan( 'edit' ) ) {
		return true;
	}

	if ( !$oldRev || !$newRev ) {
		return true;
	}

	// Don't add SpamDiffTool links to the diff view when viewing diffs via
	// Special:RCPatrol because then target will be *that* page, which in turn
	// causes line 205 of the body file to generate a fatal!
	if ( $diffEngine->getTitle()->getNamespace() < 0 ) {
		return true;
	}

	$wgOut->addHTML(
		'<table style="width:100%"><tr><td style="width:50%"></td><td style="width:50%">
		<div style="text-align:center">[' .
		// The parameters used here are slightly different than those used in
		// SpamDiffTool::getDiffLink(), hence why this reimplements some of its
		// functionality. Eventually this should also be cleaned up.
		Linker::link(
			SpecialPage::getTitleFor( 'SpamDiffTool' ),
			wfMessage( 'spamdifftool-spam-link-text' )->plain(),
			array(),
			array(
				'target' => $diffEngine->getTitle()->getPrefixedDBkey(),
				'oldid2' => $oldRev->getId(),
				'diff2' => $newRev->getId(),
				'returnto' => $_SERVER['QUERY_STRING']
			) ) .
		']</div></td></tr></table>'
	);

	return true;
};
/** This is original-ish ashley code which looks ugly; the above version (by iAlex) is so much better.
$wgHooks['DiffRevisionTools'][] = function( Revision $newRev, &$revisionTools, $oldRev ) {
	global $wgUser;
	$t = $newRev->getTitle();
	if ( $wgUser->isAllowed( 'rollback' ) && $t instanceof Title && $t->userCan( 'edit' ) ) {
		$spamLink = '<br />&nbsp;&nbsp;&nbsp;<strong>' .
			SpamDiffTool::getDiffLink( $t ) . '</strong>';
	}
	$revisionTools['mw-diff-spamdifftool'] = $spamLink;
	return true;
};
**/

/*
$wgHooks['ArticleViewFooter'][] = function( $article, $patrolFooterShown ) {
	if ( $patrolFooterShown ) {
		// @todo FIXME: this would need to go *inside* the div.patrollink div,
		// but Article::showPatrolFooter() (=our $patrolFooterShown variable)
		// appends to OutputPage via ->addHTML() instead of returning a string,
		// so we can't do nifty str_replace() voodoo here and expect the code to
		// perform well...
		//$article->getContext()->getOutput(
		SpamDiffTool::getDiffLink( $article->getTitle() );
		//);
	}
	return true;
};
*/