<?php
/**
 * Hooked functions used by SpamDiffTool.
 *
 * @file
 */
class SpamDiffToolHooks {

	/**
	 * Adds the "add to spam [blacklist]" link to the diff view.
	 *
	 * @param DifferenceEngine $diffEngine
	 * @param Revision $oldRev Revision object for the older revision
	 * @param Revision $newRev Revision object for the newer revision
	 * @return bool
	 */
	public static function onDiffViewHeader( $diffEngine, $oldRev, $newRev ) {
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
	}

	/** This is original-ish ashley code which looks ugly; the above version (by iAlex) is so much better.
	public static function onDiffRevisionTools( Revision $newRev, &$revisionTools, $oldRev ) {
		global $wgUser;
		$t = $newRev->getTitle();
		if ( $wgUser->isAllowed( 'rollback' ) && $t instanceof Title && $t->userCan( 'edit' ) ) {
			$spamLink = '<br />&nbsp;&nbsp;&nbsp;<strong>' .
				SpamDiffTool::getDiffLink( $t ) . '</strong>';
		}
		$revisionTools['mw-diff-spamdifftool'] = $spamLink;
		return true;
	}
	**/

	/*
	public static function onArticleViewFooter( $article, $patrolFooterShown ) {
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
	}
	*/
}