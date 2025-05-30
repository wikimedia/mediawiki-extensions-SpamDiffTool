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
	 */
	public static function onDifferenceEngineViewHeader( $diffEngine ) {
		global $wgSpamBlacklistArticle;

		$services = MediaWiki\MediaWikiServices::getInstance();
		$sb = MediaWiki\Title\Title::newFromDBKey( $wgSpamBlacklistArticle );
		$user = $diffEngine->getUser();
		// Don't add the link if the user cannot edit the Spam Blacklist
		if ( !$services->getPermissionManager()->userCan( 'edit', $user, $sb ) ) {
			return;
		}

		$oldRev = $diffEngine->getOldRevision();
		$newRev = $diffEngine->getNewRevision();
		if ( !$oldRev || !$newRev ) {
			return;
		}

		// Don't add SpamDiffTool links to the diff view when viewing diffs via
		// Special:RCPatrol because then target will be *that* page, which in turn
		// causes line 205 of the body file to generate a fatal!
		if ( $diffEngine->getTitle()->getNamespace() < 0 ) {
			return;
		}

		$params = [
			'target' => $diffEngine->getTitle()->getPrefixedDBkey(),
			'oldid2' => $oldRev->getId(),
			'diff2' => $newRev->getId()
		];

		// Get regular URL query params...
		$origQP = $diffEngine->getRequest()->getQueryValues();

		$diffEngine->getOutput()->addHTML(
			'<table style="width:100%"><tr><td style="width:50%"></td><td style="width:50%">
			<div style="text-align:center">[' .
			// The parameters used here are slightly different than those used in
			// SpamDiffTool::getDiffLink(), hence why this reimplements some of its
			// functionality. Eventually this should also be cleaned up.
			$services->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'SpamDiffTool' ),
				wfMessage( 'spamdifftool-spam-link-text' )->plain(),
				[],
				array_merge( $params, [
					'returnto' => $diffEngine->getTitle()->getPrefixedDBkey(),
					// ...but unset 'title' to ensure we go back to Special:SpamDiffTool
					// instead of the content page we came from
					'returntoquery' => wfArrayToCgi( array_diff_key( $origQP, [ 'title' => true ] ) )
				] )
			) .
			']</div></td></tr></table>'
		);
	}
}
