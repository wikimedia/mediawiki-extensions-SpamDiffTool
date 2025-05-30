<?php
/**
 * SpamDiffTool extension -- provides a basic way of adding new entries to the
 * Spam Blacklist from diff pages
 *
 * @file
 * @ingroup Extensions
 * @author Travis Derouin <travis@wikihow.com>
 * @author Alexandre Emsenhuber
 * @author Jack Phoenix
 * @license GPL-2.0-or-later
 * @link https://www.mediawiki.org/wiki/Extension:SpamDiffTool Documentation
 */

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class SpamDiffTool extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'SpamDiffTool' );
	}

	/**
	 * This...isn't actually used anywhere anymore, as far as I can see?
	 * --ashley 7 December 2014
	 *
	 * I think it was used in some wikiHow thing (maybe the default skin?)
	 * nearly or well over a decade ago. --ashley, 1 January 2020
	 *
	 * @param Title $title
	 * @return string
	 */
	public static function getDiffLink( $title ) {
		global $wgRequest, $wgSpamBlacklistArticle;

		$services = MediaWikiServices::getInstance();

		// can the user even edit this?
		$sb = Title::newFromDBKey( $wgSpamBlacklistArticle );
		$user = RequestContext::getMain()->getUser();
		if ( !$services->getPermissionManager()->userCan( 'edit', $user, $sb ) ) {
			return '';
		}

		$link = '[' .
			$services->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'SpamDiffTool' ),
				wfMessage( 'spamdifftool-spam-link-text' )->plain(),
				[],
				[
					'target' => $title->getPrefixedURL(),
					'oldid2' => $wgRequest->getVal( 'oldid' ),
					'rcid' => $wgRequest->getVal( 'rcid' ),
					'diff2' => $wgRequest->getVal( 'diff' ),
					'returnto' => $_SERVER['QUERY_STRING']
				]
			) . ']';
		return $link;
	}

	/**
	 * Show the special page
	 *
	 * @param string|null $par Parameter passed to the special page (target page name), if any
	 */
	public function execute( $par ) {
		global $wgSpamBlacklistArticle;

		$out = $this->getOutput();
		$user = $this->getUser();

		$out->enableOOUI();
		$request = $this->getRequest();

		$target = $request->getVal( 'target', $par );
		if ( !$target ) {
			$out->setPageTitle( $this->msg( 'badtitle' ) );
			$out->addHTML( $this->msg( 'badtitletext' ) );
			return;
		}

		$title = Title::newFromDBKey( $target );
		$diff = $request->getVal( 'diff2' );
		$rcid = $request->getInt( 'rcid' );
		$rdfrom = $request->getVal( 'rdfrom' );

		$this->setHeaders();

		$out->setHTMLTitle( $this->msg( 'pagetitle', $this->msg( 'spamdifftool-tool' ) ) );

		$services = MediaWikiServices::getInstance();
		$urlProtocols = $services->getUrlUtils()->validProtocols();

		// can the user even edit the Spam Blacklist page?
		$sb = Title::newFromDBKey( $wgSpamBlacklistArticle );
		if ( !$services->getPermissionManager()->userCan( 'edit', $user, $sb ) ) {
			$out->addHTML( $this->msg( 'spamdifftool-cant-edit' ) );
			return;
		}

		$this->outputHeader();

		// do the processing
		if ( $request->wasPosted() ) {
			if ( $request->getCheck( 'confirm' ) ) {
				$wp = $services->getWikiPageFactory()->newFromTitle( $sb );
				$text = ContentHandler::getContentText( $wp->getContent() );
				'@phan-var string $text';
				$blacklistPageId = $wp->getId();

				// If the blacklist page doesn't exist yet, use the interface
				// message provided by the Spam Blacklist extension as the
				// starting point (instructions for admins + the pre/nowiki tags)
				if ( $blacklistPageId === 0 ) {
					$blMsg = $this->msg( 'spam-blacklist' )->inContentLanguage();
					if ( !$blMsg->isDisabled() ) {
						$text = $blMsg->text();
					} else {
						wfDebugLog(
							'SpamDiffTool',
							'Spam Blacklist extension is not loaded yet or something, ' .
							'because [[MediaWiki:Spam-blacklist]] appears to be disabled'
						);
					}
				}

				// insert the before the <pre> at the bottom if there is one
				$i = strrpos( $text, '#</pre>' );
				if ( $i !== false ) {
					$text = substr( $text, 0, $i ) .
							$request->getVal( 'newurls' ) .
							"\n" . substr( $text, $i );
				} else {
					$text .= "\n" . $request->getVal( 'newurls' );
				}

				$summary = $this->msg( 'spamdifftool-summary' )->inContentLanguage()->text();
				$content = ContentHandler::makeContent( $text, $wp->getTitle() );

				// Edge case: sometimes the spam blacklist page might not exist,
				// and setting the EDIT_UPDATE flag in that case results in a
				// failure...so instead of failing, attempt to create the page!
				if ( $blacklistPageId > 0 ) {
					$flags = EDIT_UPDATE | EDIT_DEFER_UPDATES | EDIT_AUTOSUMMARY;
				} else {
					$flags = EDIT_DEFER_UPDATES | EDIT_AUTOSUMMARY;
				}

				$status = $wp->doUserEditContent( $content, $user, $summary, $flags );

				if ( $status->isGood() ) {
					$returnToStuff = $this->getReturnToTitleAndQuery();
					$returnToTitle = $returnToStuff['title'];
					$out->redirect( $returnToTitle->getFullURL( $returnToStuff['query'] ) );
				} else {
					// Something went wrong with the edit; display the returned
					// error message to the user
					$out->addHTML( $status->getMessage() );
				}

				return;
			}

			$vals = $request->getValues();
			$text = '';
			$urls = [];
			$source = $this->msg( 'spamdifftool-top-level-domains' )->inContentLanguage()->text();
			$tlds = explode( "\n", $source );

			foreach ( $vals as $key => $value ) {
				if ( preg_match( '/^(?:' . $urlProtocols . ')/', $key ) ) {
					$url = str_replace( '%2E', '.', $key );
					if ( $value == 'none' ) {
						continue;
					}

					switch ( $value ) {
						case 'domain':
							$t = '';
							foreach ( $tlds as $tld ) {
								// @phan-suppress-next-line SecurityCheck-ReDoS
								if ( preg_match( '/' . $tld . '/i', $url ) ) {
									$t = $tld;
									// @phan-suppress-next-line SecurityCheck-ReDoS
									$url = preg_replace( '/' . $tld . '/i', '', $url, 1 );
									break;
								}
							}
							$url = preg_replace( '@^(?:' . $urlProtocols . ")([^/]*\.)?([^./]+\.[^./]+).*$@", '$2', $url );
							$url = str_replace( '.', '\.', $url ); // escape the periods
							$url .= $t;
							break;
						case 'subdomain':
							$url = preg_replace( '/^(?:' . $urlProtocols . ')/', '', $url );
							$url = str_replace( '.', '\.', $url ); // escape the periods
							$url = preg_replace( '/^([^\/]*)\/.*/', '$1', $url ); // trim everything after the slash
							break;
						case 'dir':
							$url = preg_replace( '/^(?:' . $urlProtocols . ')/', '', $url );
							$url = preg_replace( "@^([^/]*\.)?([^./]+\.[^./]+(/[^/?]*)?).*$@", '$1$2', $url ); // trim everything after the slash
							$url = preg_replace( "/^(.*)\/$/", "$1", $url ); // trim trailing / if one exists
							$url = str_replace( '.', '\.', $url ); // escape the periods
							$url = str_replace( '/', '\/', $url ); // escape the slashes
							break;
					}
					if ( !isset( $urls[$url] ) ) {
						$text .= "$url\n";
						$urls[$url] = true;
					}
				}
			}

			if ( trim( $text ) == '' ) {
				$out->addWikiMsg( 'spamdifftool-no-text' );
				$this->addReturnToLink();
				return;
			}

			$fields = [];
			$oouiForm = new OOUI\FormLayout( [
				'method' => 'POST'
			] );

			$fields[] = new OOUI\HiddenInputWidget( [
				'name' => 'confirm',
				'value' => 'true',
			] );

			$fields[] = new OOUI\HiddenInputWidget( [
				'name' => 'newurls',
				'value' => $text,
			] );

			$fields[] = new OOUI\HiddenInputWidget( [
				'name' => 'returnto',
				'value' => $request->getVal( 'returnto' ),
			] );

			$fields[] = new OOUI\HiddenInputWidget( [
				'name' => 'returntoquery',
				'value' => $request->getText( 'returntoquery' ),
			] );

			$fields[] = new OOUI\HtmlSnippet(
				// Of course MW places the "class" attribute between "<a" and "href=" :^) Of course it does...
				str_replace(
					'href="',
					'target="_new" href="',
					$this->msg(
						'spamdifftool-confirm',
						'https://www.mediawiki.org/w/index.php?title=Extension_talk:SpamDiffTool&action=edit&section=new'
					)->parse()
				) .
				"\n<pre style='padding: 10px'>" . htmlspecialchars( $text ) . "</pre>\n"
			);

			$fields[] = new OOUI\ButtonInputWidget( [
				'flags' => [ 'primary', 'progressive' ],
				'type' => 'submit',
				'label' => $this->msg( 'spamdifftool-submit' )->text(),
			] );

			$oouiForm->appendContent( $fields );
			$out->addHTML( $oouiForm );

			return;
		}

		if ( !$title ) {
			$out->addWikiMsg( 'spamdifftool-no-title' );
			return;
		}

		if ( $diff !== null ) {
			// Get the last edit not by this user
			// @todo FIXME: This *can* return null...handle that!
			$current = $services->getRevisionLookup()->getRevisionByTitle( $title );
			$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

			$actorId = $services->getActorNormalization()->findActorId( $current->getUser(), $dbw );

			$s = $dbw->selectRow(
				[ 'revision', 'actor' ],
				[ 'rev_id' ],
				[
					'rev_page' => $current->getId(),
					"rev_actor <> {$actorId}"
				],
				__METHOD__,
				[
					// the USE INDEX clause below, which worked in 1.32 and older (IIRC), causes
					// Error: 1176 Key 'page_timestamp' doesn't exist in table 'actor_rev_user'
					// in 1.33+ with the new actor stuff active
					// 'USE INDEX' => 'page_timestamp',
					'ORDER BY' => 'rev_timestamp DESC'
				],
				[
					'actor' => [ 'JOIN', 'actor_id = rev_actor' ]
				]
			);

			$oldid = null;
			if ( $s ) {
				// set oldid
				$oldid = $s->rev_id;
			}

			if ( $oldid === null || $request->getInt( 'oldid2' ) < $oldid ) {
				$oldid = $request->getInt( 'oldid2' );
			}

			$contLang = $services->getContentLanguage();
			$de = new DifferenceEngine( $this->getContext(), $oldid, $diff, $rcid );
			$de->loadText();
			$ocontent = $de->getOldRevision()->getContent( SlotRecord::MAIN );
			$ncontent = $de->getNewRevision()->getContent( SlotRecord::MAIN );
			$otext = ContentHandler::getContentText( $ocontent );
			$ntext = ContentHandler::getContentText( $ncontent );
			$ota = explode( "\n", $contLang->segmentForDiff( $otext ) );
			$nta = explode( "\n", $contLang->segmentForDiff( $ntext ) );
			$diffs = new Diff( $ota, $nta );
			// iterate over the edits and get all of the changed text
			$text = '';
			foreach ( $diffs->edits as $edit ) {
				if ( $edit->type != 'copy' && $edit->closing != '' ) {
					$text .= implode( "\n", $edit->closing ) . "\n";
				}
			}
		} else {
			$page = $services->getWikiPageFactory()->newFromTitle( $title );
			$text = $page->getContent()->getNativeData();
		}

		$matches = [];
		$preg = '/(?:' . $urlProtocols . ")[^] \n'\"\>\<]*/im";
		preg_match_all( $preg, $text, $matches );

		if ( !count( $matches[0] ) ) {
			$out->addWikiMsg( 'spamdifftool-no-urls-detected' );
			$this->addReturnToLink();
			return;
		}

		// Add CSS
		$out->addModuleStyles( 'ext.spamdifftool.styles' );

		$out->addWikiMsg( 'spamdifftool-urls-detected' );

		$out->addHTML(
			'<form method="post">' .
				Html::hidden( 'returnto', $request->getVal( 'returnto' ) ) .
				'<br /><br /><table class="spamdifftool-table">'
		);

		$urls = [];
		foreach ( $matches as $match ) {
			foreach ( $match as $url ) {
				if ( isset( $urls[$url] ) ) {
					// avoid dupes
					continue;
				}

				$urls[$url] = true;
				$name = htmlspecialchars( str_replace( '.', '%2E', $url ) );
				$out->addHTML(
					"<tr>
						<td class='spam-url-row'><b>$url</b><br />" .
							$this->msg( 'spamdifftool-block' )->escaped() . " &nbsp;&nbsp;" .
							new OOUI\RadioInputWidget( [ 'name' => $name, 'value' => 'domain', 'selected' => true ] ) .
								$this->msg( 'spamdifftool-option-domain' )->escaped() . " &nbsp;" .
							new OOUI\RadioInputWidget( [ 'name' => $name, 'value' => 'subdomain' ] ) .
								$this->msg( 'spamdifftool-option-subdomain' )->escaped() . " &nbsp;" .
							new OOUI\RadioInputWidget( [ 'name' => $name, 'value' => 'dir' ] ) .
								$this->msg( 'spamdifftool-option-directory' )->escaped() . " &nbsp;" .
							new OOUI\RadioInputWidget( [ 'name' => $name, 'value' => 'none' ] ) .
								" " . $this->msg( 'spamdifftool-option-none' )->escaped() .
					"</td>
					</tr>"
				);
			}
		}

		$out->addHTML(
			'</table>' .
			new OOUI\ButtonInputWidget( [
				'label' => $this->msg( 'spamdifftool-submit' )->plain(),
				'type' => 'submit'
			] ) .
			'</form>'
		);
	}

	/**
	 * Add a "return to" link to the OutputPage w/ the appropriate query parameters set.
	 *
	 * @see getReturnToTitleAndQuery() below
	 */
	private function addReturnToLink() {
		$stuff = $this->getReturnToTitleAndQuery();
		$title = $stuff['title'];
		$query = $stuff['query'];
		$this->getOutput()->addReturnTo( $title, wfCgiToArray( $query ) );
	}

	/**
	 * return/returntoquery URL param handling, lovingly sporked from core includes/specials/SpecialChangeEmail.php
	 * as of 24 June 2024.
	 * Perhaps one day core will have proper, centralized returnto/returntoquery handling instead of requiring
	 * extensions and the like to reimplement the wheel over and over and over...
	 *
	 * @return array An array containing [ 'title' => Title, 'query' => string ]
	 */
	private function getReturnToTitleAndQuery() {
		$request = $this->getRequest();
		$returnTo = $request->getVal( 'returnto' );
		$titleObj = null;
		if ( $returnTo !== null ) {
			$titleObj = Title::newFromText( $returnTo );
		}
		if ( !$titleObj instanceof Title ) {
			$titleObj = Title::newMainPage();
		}
		$query = $request->getVal( 'returntoquery', '' );

		return [
			'title' => $titleObj,
			'query' => $query
		];
	}
}
