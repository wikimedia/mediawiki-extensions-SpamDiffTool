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
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link https://www.mediawiki.org/wiki/Extension:SpamDiffTool Documentation
 */
class SpamDiffTool extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'SpamDiffTool' );
	}

	/**
	 * This...isn't actually used anywhere anymore, as far as I can see?
	 * --ashley 7 December 2014
	 */
	public static function getDiffLink( $title ) {
		global $wgRequest, $wgSpamBlacklistArticle;

		$sb = Title::newFromDBKey( $wgSpamBlacklistArticle );

		if ( !$sb->userCan( 'edit' ) ) {
			return '';
		}

		$link = '[' .
			MediaWiki\MediaWikiServices::getInstance()->getLinkRenderer()->makeKnownLink(
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
	 * @param mixed|null $par Parameter passed to the special page
	 */
	public function execute( $par ) {
		global $wgActorTableSchemaMigrationStage, $wgSpamBlacklistArticle, $wgScript;

		$out = $this->getOutput();
		$out->enableOOUI();
		$request = $this->getRequest();

		$title = Title::newFromDBKey( $request->getVal( 'target' ) );
		$diff = $request->getVal( 'diff2' );
		$rcid = $request->getVal( 'rcid' );
		$rdfrom = $request->getVal( 'rdfrom' );

		$this->setHeaders();

		$out->setHTMLTitle( $this->msg( 'pagetitle', $this->msg( 'spamdifftool-tool' ) ) );

		// can the user even edit this?
		$sb = Title::newFromDBKey( $wgSpamBlacklistArticle );
		if ( !$sb->userCan( 'edit' ) ) {
			$out->addHTML( $this->msg( 'spamdifftool-cant-edit' ) );
			return;
		}

		$this->outputHeader();

		// do the processing
		if ( $request->wasPosted() ) {
			if ( $request->getCheck( 'confirm' ) ) {
				$wp = WikiPage::factory( $sb );
				$text = ContentHandler::getContentText( $wp->getContent() );
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
				$status = $wp->doEditContent( $content, $summary, $flags );

				if ( $status->isGood() ) {
					$returnto = $request->getVal( 'returnto', null );
					if ( $returnto != null && $returnto != '' ) {
						$out->redirect( $wgScript . '?' . urldecode( $returnto ) );
					}
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
				if ( preg_match( '/^(?:' . wfUrlProtocols() . ')/', $key ) ) {
					$url = str_replace( '%2E', '.', $key );
					if ( $value == 'none' ) {
						continue;
					}

					switch ( $value ) {
						case 'domain':
							$t = '';
							foreach ( $tlds as $tld ) {
								if ( preg_match( '/' . $tld . '/i', $url ) ) {
									$t = $tld;
									$url = preg_replace( '/' . $tld . '/i', '', $url,  1 );
									break;
								}
							}
							$url = preg_replace( '@^(?:' . wfUrlProtocols() . ")([^/]*\.)?([^./]+\.[^./]+).*$@", '$2', $url );
							$url = str_replace( '.', '\.', $url ); // escape the periods
							$url .= $t;
							break;
						case 'subdomain':
							$url = preg_replace( '/^(?:' . wfUrlProtocols() . ')/', '', $url );
							$url = str_replace( '.', '\.', $url ); // escape the periods
							$url = preg_replace( '/^([^\/]*)\/.*/', '$1', $url ); // trim everything after the slash
							break;
						case 'dir':
							$url = preg_replace( '/^(?:' . wfUrlProtocols() . ')/', '', $url );
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
				$out->addHTML( $this->msg( 'spamdifftool-no-text', $wgScript . '?' . urldecode( $request->getVal( 'returnto' ) ) )->text() );
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

			$fields[] = new OOUI\HtmlSnippet(
				$this->msg(
					'spamdifftool-confirm',
					'https://www.mediawiki.org/w/index.php?title=Extension_talk:SpamDiffTool&action=edit&section=new'
				)->text() .
				"\n<pre style='padding: 10px'>$text</pre>\n"
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

		if ( !is_null( $diff ) ) {
			// Get the last edit not by this user
			$services = MediaWiki\MediaWikiServices::getInstance();
			// @todo FIXME: This *can* return null...handle that!
			$current = $services->getRevisionLookup()->getRevisionByTitle( $title );
			$dbw = wfGetDB( DB_MASTER );

			$user = intval( $current->getUser()->getId() );
			$user_text = $dbw->addQuotes( $current->getUser()->getName() );

			$revQuery = $services->getRevisionStore()->getQueryInfo();
			$pageField = ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_READ_NEW )
				? 'revactor_page' : 'rev_page';
			$whereCond = ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_READ_NEW )
				? "revactor_actor <> {$current->getUser()->getActorId()}"
				: "rev_user <> {$user} OR rev_user_text <> {$user_text}";
			if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
				// the USE INDEX clause below, which worked in 1.32 and older (IIRC), causes
				// Error: 1176 Key 'page_timestamp' doesn't exist in table 'actor_rev_user'
				// in 1.33+ with the new actor stuff active
				$options = [
					'ORDER BY' => 'rev_timestamp DESC'
				];
			} else {
				$options = [
					'USE INDEX' => 'page_timestamp',
					'ORDER BY' => 'rev_timestamp DESC'
				];
			}

			$s = $dbw->selectRow(
				$revQuery['tables'],
				$revQuery['fields'],
				[
					$pageField => $current->getId(),
					$whereCond
				],
				__METHOD__,
				$options,
				$revQuery['joins']
			);

			$oldid = null;
			if ( $s ) {
				// set oldid
				$oldid = $s->rev_id;
			}

			if ( $request->getVal( 'oldid2' ) < $oldid ) {
				$oldid = $request->getVal( 'oldid2' );
			}

			$contLang = $services->getContentLanguage();
			$de = new DifferenceEngine( $title, $oldid, $diff, $rcid );
			$de->loadText();
			$ocontent = $de->mOldRev->getContent();
			$ncontent = $de->mNewRev->getContent();
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
			if ( $title != '' ) {
				$page = new WikiPage( $title );
				$text = $page->getContent()->getNativeData();
			}
		}

		$matches = [];
		$preg = '/(?:' . wfUrlProtocols() . ")[^] \n'\"\>\<]*/im";
		preg_match_all( $preg, $text, $matches );

		if ( !count( $matches[0] ) ) {
			$out->addHTML( $this->msg( 'spamdifftool-no-urls-detected', $wgScript . '?' . urldecode( $request->getVal( 'returnto' ) ) )->text() );
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

}
