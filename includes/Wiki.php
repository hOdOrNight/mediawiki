<?php
/**
 * Helper class for the index.php entry point.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * The MediaWiki class is the helper class for the index.php entry point.
 *
 * @internal documentation reviewed 15 Mar 2010
 */
class MediaWiki {

	/**
	 * TODO: fold $output, etc, into this
	 * @var IContextSource
	 */
	private $context;

	/**
	 * @var string
	 */
	private $performedAction = 'nosuchaction';

	public function request( WebRequest $x = null ) {
		$old = $this->context->getRequest();
		$this->context->setRequest( $x );
		return $old;
	}

	public function output( OutputPage $x = null ) {
		$old = $this->context->getOutput();
		$this->context->setOutput( $x );
		return $old;
	}

	public function __construct( IContextSource $context = null ) {
		if ( !$context ) {
			$context = RequestContext::getMain();
		}

		$this->context = $context;
		$this->context->setTitle( $this->parseTitle() );
	}

	/**
	 * Parse the request to get the Title object
	 *
	 * @return Title object to be $wgTitle
	 */
	private function parseTitle() {
		global $wgContLang;

		$request = $this->context->getRequest();
		$curid = $request->getInt( 'curid' );
		$title = $request->getVal( 'title' );

		if ( $request->getCheck( 'search' ) ) {
			// Compatibility with old search URLs which didn't use Special:Search
			// Just check for presence here, so blank requests still
			// show the search page when using ugly URLs (bug 8054).
			$ret = SpecialPage::getTitleFor( 'Search' );
		} elseif ( $curid ) {
			// URLs like this are generated by RC, because rc_title isn't always accurate
			$ret = Title::newFromID( $curid );
		} elseif ( $title == '' && $this->getAction() != 'delete' ) {
			$ret = Title::newMainPage();
		} else {
			$ret = Title::newFromURL( $title );
			// Alias NS_MEDIA page URLs to NS_FILE...we only use NS_MEDIA
			// in wikitext links to tell Parser to make a direct file link
			if ( !is_null( $ret ) && $ret->getNamespace() == NS_MEDIA ) {
				$ret = Title::makeTitle( NS_FILE, $ret->getDBkey() );
			}
			// Check variant links so that interwiki links don't have to worry
			// about the possible different language variants
			if ( count( $wgContLang->getVariants() ) > 1
				&& !is_null( $ret ) && $ret->getArticleID() == 0 )
			{
				$wgContLang->findVariantLink( $title, $ret );
			}
		}
		// For non-special titles, check for implicit titles
		if ( is_null( $ret ) || !$ret->isSpecialPage() ) {
			// We can have urls with just ?diff=,?oldid= or even just ?diff=
			$oldid = $request->getInt( 'oldid' );
			$oldid = $oldid ? $oldid : $request->getInt( 'diff' );
			// Allow oldid to override a changed or missing title
			if ( $oldid ) {
				$rev = Revision::newFromId( $oldid );
				$ret = $rev ? $rev->getTitle() : $ret;
			}
		}

		if ( $ret === null || ( $ret->getDBkey() == '' && $ret->getInterwiki() == '' ) ) {
			$ret = SpecialPage::getTitleFor( 'Badtitle' );
		}

		return $ret;
	}

	/**
	 * Get the Title object that we'll be acting on, as specified in the WebRequest
	 * @return Title
	 */
	public function getTitle() {
		if( $this->context->getTitle() === null ){
			$this->context->setTitle( $this->parseTitle() );
		}
		return $this->context->getTitle();
	}

	/**
	 * Performs the request.
	 * - bad titles
	 * - read restriction
	 * - local interwiki redirects
	 * - redirect loop
	 * - special pages
	 * - normal pages
	 *
	 * @return void
	 */
	private function performRequest() {
		global $wgServer, $wgUsePathInfo, $wgTitle;

		wfProfileIn( __METHOD__ );

		$request = $this->context->getRequest();
		$title = $this->context->getTitle();
		$output = $this->context->getOutput();
		$user = $this->context->getUser();

		if ( $request->getVal( 'printable' ) === 'yes' ) {
			$output->setPrintable();
		}

		$unused = null; // To pass it by reference
		wfRunHooks( 'BeforeInitialize', array( &$title, &$unused, &$output, &$user, $request, $this ) );

		// Invalid titles. Bug 21776: The interwikis must redirect even if the page name is empty.
		if ( is_null( $title ) || ( $title->getDBkey() == '' && $title->getInterwiki() == '' ) ||
			$title->isSpecial( 'Badtitle' ) )
		{
			$this->context->setTitle( SpecialPage::getTitleFor( 'Badtitle' ) );
			wfProfileOut( __METHOD__ );
			throw new ErrorPageError( 'badtitle', 'badtitletext' );
		}

		// Check user's permissions to read this page.
		// We have to check here to catch special pages etc.
		// We will check again in Article::view().
		$permErrors = $title->getUserPermissionsErrors( 'read', $user );
		if ( count( $permErrors ) ) {
			// Bug 32276: allowing the skin to generate output with $wgTitle or
			// $this->context->title set to the input title would allow anonymous users to
			// determine whether a page exists, potentially leaking private data. In fact, the
			// curid and oldid request  parameters would allow page titles to be enumerated even
			// when they are not guessable. So we reset the title to Special:Badtitle before the
			// permissions error is displayed.
			//
			// The skin mostly uses $this->context->getTitle() these days, but some extensions
			// still use $wgTitle.

			$badTitle = SpecialPage::getTitleFor( 'Badtitle' );
			$this->context->setTitle( $badTitle );
			$wgTitle = $badTitle;

			wfProfileOut( __METHOD__ );
			throw new PermissionsError( 'read', $permErrors );
		}

		$pageView = false; // was an article or special page viewed?

		// Interwiki redirects
		if ( $title->getInterwiki() != '' ) {
			$rdfrom = $request->getVal( 'rdfrom' );
			if ( $rdfrom ) {
				$url = $title->getFullURL( 'rdfrom=' . urlencode( $rdfrom ) );
			} else {
				$query = $request->getValues();
				unset( $query['title'] );
				$url = $title->getFullURL( $query );
			}
			// Check for a redirect loop
			if ( !preg_match( '/^' . preg_quote( $wgServer, '/' ) . '/', $url )
				&& $title->isLocal() )
			{
				// 301 so google et al report the target as the actual url.
				$output->redirect( $url, 301 );
			} else {
				$this->context->setTitle( SpecialPage::getTitleFor( 'Badtitle' ) );
				wfProfileOut( __METHOD__ );
				throw new ErrorPageError( 'badtitle', 'badtitletext' );
			}
		// Redirect loops, no title in URL, $wgUsePathInfo URLs, and URLs with a variant
		} elseif ( $request->getVal( 'action', 'view' ) == 'view' && !$request->wasPosted()
			&& ( $request->getVal( 'title' ) === null ||
				$title->getPrefixedDBKey() != $request->getVal( 'title' ) )
			&& !count( $request->getValueNames( array( 'action', 'title' ) ) )
			&& wfRunHooks( 'TestCanonicalRedirect', array( $request, $title, $output ) ) )
		{
			if ( $title->isSpecialPage() ) {
				list( $name, $subpage ) = SpecialPageFactory::resolveAlias( $title->getDBkey() );
				if ( $name ) {
					$title = SpecialPage::getTitleFor( $name, $subpage );
				}
			}
			$targetUrl = wfExpandUrl( $title->getFullURL(), PROTO_CURRENT );
			// Redirect to canonical url, make it a 301 to allow caching
			if ( $targetUrl == $request->getFullRequestURL() ) {
				$message = "Redirect loop detected!\n\n" .
					"This means the wiki got confused about what page was " .
					"requested; this sometimes happens when moving a wiki " .
					"to a new server or changing the server configuration.\n\n";

				if ( $wgUsePathInfo ) {
					$message .= "The wiki is trying to interpret the page " .
						"title from the URL path portion (PATH_INFO), which " .
						"sometimes fails depending on the web server. Try " .
						"setting \"\$wgUsePathInfo = false;\" in your " .
						"LocalSettings.php, or check that \$wgArticlePath " .
						"is correct.";
				} else {
					$message .= "Your web server was detected as possibly not " .
						"supporting URL path components (PATH_INFO) correctly; " .
						"check your LocalSettings.php for a customized " .
						"\$wgArticlePath setting and/or toggle \$wgUsePathInfo " .
						"to true.";
				}
				throw new HttpError( 500, $message );
			} else {
				$output->setSquidMaxage( 1200 );
				$output->redirect( $targetUrl, '301' );
			}
		// Special pages
		} elseif ( NS_SPECIAL == $title->getNamespace() ) {
			$pageView = true;
			// Actions that need to be made when we have a special pages
			SpecialPageFactory::executePath( $title, $this->context );
		} else {
			// ...otherwise treat it as an article view. The article
			// may be a redirect to another article or URL.
			$article = $this->initializeArticle();
			if ( is_object( $article ) ) {
				$pageView = true;
				/**
				 * $wgArticle is deprecated, do not use it.
				 * This will be removed entirely in 1.20.
				 * @deprecated since 1.18
				 */
				global $wgArticle;
				$wgArticle = $article;

				$this->performAction( $article );
			} elseif ( is_string( $article ) ) {
				$output->redirect( $article );
			} else {
				wfProfileOut( __METHOD__ );
				throw new MWException( "Shouldn't happen: MediaWiki::initializeArticle() returned neither an object nor a URL" );
			}
		}

		if ( $pageView ) {
			// Promote user to any groups they meet the criteria for
			$user->addAutopromoteOnceGroups( 'onView' );
		}

		wfProfileOut( __METHOD__ );
	}

	/**
	 * Create an Article object of the appropriate class for the given page.
	 *
	 * @deprecated in 1.18; use Article::newFromTitle() instead
	 * @param $title Title
	 * @param $context IContextSource
	 * @return Article object
	 */
	public static function articleFromTitle( $title, IContextSource $context ) {
		wfDeprecated( __METHOD__, '1.18' );
		return Article::newFromTitle( $title, $context );
	}

	/**
	 * Returns the action that will be executed, not necessarily the one passed
	 * passed through the "action" parameter. Actions disabled in
	 * $wgDisabledActions will be replaced by "nosuchaction".
	 *
	 * The return value is merely a suggestion, not the actually performed action,
	 * which may be different. The actually performed action is determined by performAction().
	 * Requests like action=nonsense will make this function return "nonsense".
	 * Use getPerformedAction() to get the performed action.
	 *
	 * @return string: action
	 */
	public function getAction() {
		global $wgDisabledActions;

		$request = $this->context->getRequest();
		$action = $request->getVal( 'action', 'view' );

		// Check for disabled actions
		if ( in_array( $action, $wgDisabledActions ) ) {
			return 'nosuchaction';
		}

		// Workaround for bug #20966: inability of IE to provide an action dependent
		// on which submit button is clicked.
		if ( $action === 'historysubmit' ) {
			if ( $request->getBool( 'revisiondelete' ) ) {
				return 'revisiondelete';
			} else {
				return 'view';
			}
		} elseif ( $action == 'editredlink' ) {
			return 'edit';
		}

		return $action;
	}

	/**
	 * Initialize the main Article object for "standard" actions (view, etc)
	 * Create an Article object for the page, following redirects if needed.
	 *
	 * @return mixed an Article, or a string to redirect to another URL
	 */
	private function initializeArticle() {
		global $wgDisableHardRedirects;

		wfProfileIn( __METHOD__ );

		$title = $this->context->getTitle();
		$article = Article::newFromTitle( $title, $this->context );
		$this->context->setWikiPage( $article->getPage() );
		// NS_MEDIAWIKI has no redirects.
		// It is also used for CSS/JS, so performance matters here...
		if ( $title->getNamespace() == NS_MEDIAWIKI ) {
			wfProfileOut( __METHOD__ );
			return $article;
		}

		$request = $this->context->getRequest();

		// Namespace might change when using redirects
		// Check for redirects ...
		$action = $request->getVal( 'action', 'view' );
		$file = ( $title->getNamespace() == NS_FILE ) ? $article->getFile() : null;
		if ( ( $action == 'view' || $action == 'render' ) 	// ... for actions that show content
			&& !$request->getVal( 'oldid' ) &&    // ... and are not old revisions
			!$request->getVal( 'diff' ) &&    // ... and not when showing diff
			$request->getVal( 'redirect' ) != 'no' &&	// ... unless explicitly told not to
			// ... and the article is not a non-redirect image page with associated file
			!( is_object( $file ) && $file->exists() && !$file->getRedirected() ) )
		{
			// Give extensions a change to ignore/handle redirects as needed
			$ignoreRedirect = $target = false;

			wfRunHooks( 'InitializeArticleMaybeRedirect',
				array( &$title, &$request, &$ignoreRedirect, &$target, &$article ) );

			// Follow redirects only for... redirects.
			// If $target is set, then a hook wanted to redirect.
			if ( !$ignoreRedirect && ( $target || $article->isRedirect() ) ) {
				// Is the target already set by an extension?
				$target = $target ? $target : $article->followRedirect();
				if ( is_string( $target ) ) {
					if ( !$wgDisableHardRedirects ) {
						// we'll need to redirect
						wfProfileOut( __METHOD__ );
						return $target;
					}
				}
				if ( is_object( $target ) ) {
					// Rewrite environment to redirected article
					$rarticle = Article::newFromTitle( $target, $this->context );
					$rarticle->loadPageData();
					if ( $rarticle->exists() || ( is_object( $file ) && !$file->isLocal() ) ) {
						$rarticle->setRedirectedFrom( $title );
						$article = $rarticle;
						$this->context->setTitle( $target );
						$this->context->setWikiPage( $article->getPage() );
					}
				}
			} else {
				$this->context->setTitle( $article->getTitle() );
				$this->context->setWikiPage( $article->getPage() );
			}
		}

		wfProfileOut( __METHOD__ );
		return $article;
	}

	/**
	 * Cleaning up request by doing deferred updates, DB transaction, and the output
	 */
	public function finalCleanup() {
		wfProfileIn( __METHOD__ );
		// Now commit any transactions, so that unreported errors after
		// output() don't roll back the whole DB transaction
		$factory = wfGetLBFactory();
		$factory->commitMasterChanges();
		// Output everything!
		$this->context->getOutput()->output();
		// Do any deferred jobs
		DeferredUpdates::doUpdates( 'commit' );
		$this->doJobs();
		wfProfileOut( __METHOD__ );
	}

	/**
	 * Do a job from the job queue
	 */
	private function doJobs() {
		global $wgJobRunRate;

		if ( $wgJobRunRate <= 0 || wfReadOnly() ) {
			return;
		}
		if ( $wgJobRunRate < 1 ) {
			$max = mt_getrandmax();
			if ( mt_rand( 0, $max ) > $max * $wgJobRunRate ) {
				return;
			}
			$n = 1;
		} else {
			$n = intval( $wgJobRunRate );
		}

		while ( $n-- && false != ( $job = Job::pop() ) ) {
			$output = $job->toString() . "\n";
			$t = -wfTime();
			$success = $job->run();
			$t += wfTime();
			$t = round( $t * 1000 );
			if ( !$success ) {
				$output .= "Error: " . $job->getLastError() . ", Time: $t ms\n";
			} else {
				$output .= "Success, Time: $t ms\n";
			}
			wfDebugLog( 'jobqueue', $output );
		}
	}

	/**
	 * Ends this task peacefully
	 */
	public function restInPeace() {
		MessageCache::logMessages();
		wfLogProfilingData();
		// Commit and close up!
		$factory = wfGetLBFactory();
		$factory->commitMasterChanges();
		$factory->shutdown();
		wfDebug( "Request ended normally\n" );
	}

	/**
	 * Perform one of the "standard" actions
	 *
	 * @param $article Article
	 */
	private function performAction( Page $article ) {
		global $wgSquidMaxage;

		wfProfileIn( __METHOD__ );

		$request = $this->context->getRequest();
		$output = $this->context->getOutput();
		$title = $this->context->getTitle();
		$user = $this->context->getUser();

		if ( !wfRunHooks( 'MediaWikiPerformAction',
			array( $output, $article, $title, $user, $request, $this ) ) )
		{
			wfProfileOut( __METHOD__ );
			return;
		}

		$act = $this->getAction();

		$action = Action::factory( $act, $article );
		if ( $action instanceof Action ) {
			$this->performedAction = $act;
			$action->show();
			wfProfileOut( __METHOD__ );
			return;
		}

		switch( $act ) {
			case 'view':
				$output->setSquidMaxage( $wgSquidMaxage );
				$this->performedAction = $act;
				$article->view();
				break;
			case 'delete':
			case 'protect':
			case 'unprotect':
			case 'render':
				$this->performedAction = $act;
				$article->$act();
				break;
			case 'submit':
				if ( session_id() == '' ) {
					// Send a cookie so anons get talk message notifications
					wfSetupSession();
				}
				// Continue...
			case 'edit':
				if ( wfRunHooks( 'CustomEditor', array( $article, $user ) ) ) {
					$this->performedAction = 'edit';
					if ( ExternalEdit::useExternalEngine( $this->context, 'edit' )
						&& $act == 'edit' && !$request->getVal( 'section' )
						&& !$request->getVal( 'oldid' ) )
					{
						$extedit = new ExternalEdit( $this->context );
						$extedit->execute();
					} else {
						$editor = new EditPage( $article );
						$editor->edit();
					}
				}
				break;
			default:
				if ( wfRunHooks( 'UnknownAction', array( $act, $article ) ) ) {
					$this->performedAction = 'nosuchaction';
					$output->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
				}
		}
		wfProfileOut( __METHOD__ );
	}

	/**
	 * Returns the real action as determined by performAction.
	 * Do not use internally in this class as it depends on the actions by this class.
	 *
	 * @since 1.19
	 *
	 * @return string: action
	 */
	public function getPerformedAction() {
		return $this->performedAction;
	}

	/**
	 * Run the current MediaWiki instance
	 * index.php just calls this
	 */
	public function run() {
		try {
			$this->checkMaxLag();
			$this->main();
			$this->restInPeace();
		} catch ( Exception $e ) {
			MWExceptionHandler::handle( $e );
		}
	}

	/**
	 * Checks if the request should abort due to a lagged server,
	 * for given maxlag parameter.
	 */
	private function checkMaxLag() {
		global $wgShowHostnames;

		wfProfileIn( __METHOD__ );
		$maxLag = $this->context->getRequest()->getVal( 'maxlag' );
		if ( !is_null( $maxLag ) ) {
			list( $host, $lag ) = wfGetLB()->getMaxLag();
			if ( $lag > $maxLag ) {
				$resp = $this->context->getRequest()->response();
				$resp->header( 'HTTP/1.1 503 Service Unavailable' );
				$resp->header( 'Retry-After: ' . max( intval( $maxLag ), 5 ) );
				$resp->header( 'X-Database-Lag: ' . intval( $lag ) );
				$resp->header( 'Content-Type: text/plain' );
				if( $wgShowHostnames ) {
					echo "Waiting for $host: $lag seconds lagged\n";
				} else {
					echo "Waiting for a database server: $lag seconds lagged\n";
				}

				wfProfileOut( __METHOD__ );

				exit;
			}
		}
		wfProfileOut( __METHOD__ );
		return true;
	}

	private function main() {
		global $wgUseFileCache, $wgTitle, $wgUseAjax;

		wfProfileIn( __METHOD__ );

		# Set title from request parameters
		$wgTitle = $this->getTitle();
		$action = $this->getAction();

		# Send Ajax requests to the Ajax dispatcher.
		if ( $wgUseAjax && $action == 'ajax' ) {
			$dispatcher = new AjaxDispatcher();
			$dispatcher->performAction();
			wfProfileOut( __METHOD__ );
			return;
		}

		if ( $wgUseFileCache && $this->getTitle()->getNamespace() >= 0 ) {
			wfProfileIn( 'main-try-filecache' );
			if ( HTMLFileCache::useFileCache( $this->context ) ) {
				/* Try low-level file cache hit */
				$cache = HTMLFileCache::newFromTitle( $this->getTitle(), $action );
				if ( $cache->isCacheGood( /* Assume up to date */ ) ) {
					/* Check incoming headers to see if client has this cached */
					$timestamp = $cache->cacheTimestamp();
					if ( !$this->context->getOutput()->checkLastModified( $timestamp ) ) {
						$cache->loadFromFileCache( $this->context );
					}
					# Do any stats increment/watchlist stuff
					$this->context->getWikiPage()->doViewUpdates( $this->context->getUser() );
					# Tell OutputPage that output is taken care of
					$this->context->getOutput()->disable();
					wfProfileOut( 'main-try-filecache' );
					wfProfileOut( __METHOD__ );
					return;
				}
			}
			wfProfileOut( 'main-try-filecache' );
		}

		$this->performRequest();
		$this->finalCleanup();

		wfProfileOut( __METHOD__ );
	}
}
