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
			Linker::linkKnown(
				SpecialPage::getTitleFor( 'SpamDiffTool' ),
				wfMessage( 'spamdifftool-spam-link-text' )->plain(),
				array(),
				array(
					'target' => $title->getPrefixedURL(),
					'oldid2' => $wgRequest->getVal( 'oldid' ),
					'rcid' => $wgRequest->getVal( 'rcid' ),
					'diff2' => $wgRequest->getVal( 'diff' ),
					'returnto' => $_SERVER['QUERY_STRING']
				)
			) . ']';
		return $link;
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the special page
	 */
	public function execute( $par ) {
		global $wgContLang, $wgSpamBlacklistArticle, $wgScript;

		$out = $this->getOutput();
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
				$a = new Article( $sb );
				$acontent = $a->getContentObject();
				$text = ContentHandler::getContentText( $acontent );
				$blacklistPageId = $a->getId();

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
							'because [[MediaWiki:Spam-blacklist]] appear to be disabled'
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
				$content = ContentHandler::makeContent( $text, $a->getTitle() );

				// Edge case: sometimes the spam blacklist page might not exist,
				// and setting the EDIT_UPDATE flag in that case results in a
				// failure...so instead of failing, attempt to create the page!
				if ( $blacklistPageId > 0 ) {
					$flags = EDIT_UPDATE | EDIT_DEFER_UPDATES | EDIT_AUTOSUMMARY;
				} else {
					$flags = EDIT_DEFER_UPDATES | EDIT_AUTOSUMMARY;
				}
				$status = $a->doEditContent( $content, $summary, $flags );

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
			$urls = array();
			$source = $this->msg( 'spamdifftool-top-level-domains' )->inContentLanguage()->text();
			$tlds = explode( "\n", $source );

			foreach ( $vals as $key => $value ) {
				if ( strpos( $key, 'http://' ) === 0 ) {
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
							$url = preg_replace( "@^http://([^/]*\.)?([^./]+\.[^./]+).*$@", '$2', $url );
							$url = str_replace( '.', '\.', $url ); // escape the periods
							$url .= $t;
							break;
						case 'subdomain':
							$url = str_replace( 'http://', '', $url );
							$url = str_replace( '.', '\.', $url ); // escape the periods
							$url = preg_replace( '/^([^\/]*)\/.*/', '$1', $url ); // trim everything after the slash
							break;
						case 'dir':
							$url = str_replace( 'http://', '', $url );
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

			$out->addHTML(
				'<form method="post">' .
					Html::hidden( 'confirm', 'true' ) .
					Html::hidden( 'newurls', $text ) .
					Html::hidden( 'returnto', $request->getVal( 'returnto' ) ) .
					$this->msg(
						'spamdifftool-confirm',
						'https://www.mediawiki.org/w/index.php?title=Extension_talk:SpamDiffTool&action=edit&section=new'
					)->text() .
					"\n<pre style='padding: 10px'>$text</pre>\n" .
					'</table>' .
					Html::input( '', $this->msg( 'spamdifftool-submit' )->plain(), 'submit' ) .
				'</form>'
			);
			return;
		}

		if ( !$title ) {
			$out->addWikiMsg( 'spamdifftool-no-title' );
			return;
		}

		if ( !is_null( $diff ) ) {
			// Get the last edit not by this user
			$current = Revision::newFromTitle( $title );
			$dbw = wfGetDB( DB_MASTER );
			$user = intval( $current->getUser() );
			$user_text = $dbw->addQuotes( $current->getUserText() );

			$s = $dbw->selectRow(
				'revision',
				array( 'rev_id', 'rev_timestamp' ),
				array(
					'rev_page' => $current->getPage(),
					"rev_user <> {$user} OR rev_user_text <> {$user_text}"
				),
				__METHOD__,
				array(
					'USE INDEX' => 'page_timestamp',
					'ORDER BY' => 'rev_timestamp DESC'
				)
			);

			$oldid = null;
			if ( $s ) {
				// set oldid
				$oldid = $s->rev_id;
			}

			if ( $request->getVal( 'oldid2' ) < $oldid ) {
				$oldid = $request->getVal( 'oldid2' );
			}

			$de = new DifferenceEngine( $title, $oldid, $diff, $rcid );
			$de->loadText();
			$ocontent = $de->$mOldRev->getContent();
			$ncontent = $de->$mNewRev->getContent();
			$otext = ContentHandler::getContentText( $ocontent );
			$ntext = ContentHandler::getContentText( $ncontent );
			$ota = explode( "\n", $wgContLang->segmentForDiff( $otext ) );
			$nta = explode( "\n", $wgContLang->segmentForDiff( $ntext ) );
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

		$matches = array();
		$preg = "/http:\/\/[^] \n'\"\>\<]*/im";
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

		$urls = array();
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
						<td class='spam-url-row'><b>$url</b><br />
						" . $this->msg( 'spamdifftool-block' )->plain() . " &nbsp;&nbsp;
						<input type='radio' name=\"" . $name . "\" value='domain' checked /> " .
							$this->msg( 'spamdifftool-option-domain' )->plain() . "
						<input type='radio' name=\"" . $name . "\" value='subdomain' /> " .
							$this->msg( 'spamdifftool-option-subdomain' )->plain() . "
						<input type='radio' name=\"" . $name . "\" value='dir' />" .
							$this->msg( 'spamdifftool-option-directory' )->plain() . "
						<input type='radio' name=\"" . $name . "\" value='none' />" .
							$this->msg( 'spamdifftool-option-none' )->plain() . "
					</td>
					</tr>"
				);
			}
		}

		$out->addHTML(
			'</table>' .
			Html::input( '', $this->msg( 'spamdifftool-submit' )->plain(), 'submit' ) .
			'</form>'
		);
	}

}
