<?php

namespace Arclight\ImportBundle\Utils;

use Symfony\Component\Console\Output\OutputInterface;
use Arclight\ImportBundle\Tests\Social\FacebookImageRemote;
use Arclight\ImportBundle\Tests\Social\FacebookCaptionsFile;
use Symfony\Component\Console\Output\NullOutput;
use Arclight\MMDBBundle\php\misc\DatabaseUtils;

class YouTubeServiceBuilder 
{
	public const DEFAULT_REDIRECT_URI = 'http://localhost:8888/app_dev.php/callback/googleoauth';
	
	public const YOUTUBE_CLIENT_TYPE_SERVICE = "service";
	public const YOUTUBE_CLIENT_TYPE_OTHER = "Other";
	public const YOUTUBE_CLIENT_TYPE_WEB = "Web application";
	
	public const SCOPE_YOUTUBE = "https://www.googleapis.com/auth/youtube";
	public const SCOPE_YOUTUBE_FORCESSL = "https://www.googleapis.com/auth/youtube.force-ssl";
	public const SCOPE_YOUTUBE_READONLY = "https://www.googleapis.com/auth/youtube.readonly";
	public const SCOPE_YOUTUBE_UPLOAD = "https://www.googleapis.com/auth/youtube.upload";
	public const SCOPE_YOUTUBE_PARTNER = "https://www.googleapis.com/auth/youtubepartner";
	public const SCOPE_YOUTUBE_PARTNER_CHANNEL_AUDIT = "https://www.googleapis.com/auth/youtubepartner-channel-audit";
	public const SCOPE_YOUTUBE_ANALYTICS_MONETARY_READONLY = "https://www.googleapis.com/auth/yt-analytics-monetary.readonly";
	public const SCOPE_YOUTUBE_ANALYTICS_READONLY = "https://www.googleapis.com/auth/yt-analytics.readonly";
	
	public const SCOPE_GROUP_YOUTUBE_DATA_DEVICE = "scopeGroupYoutubeDataDevice";
	public const SCOPE_GROUP_YOUTUBE_DATA = "scopeGroupYoutubeData";
	public const SCOPE_GROUP_YOUTUBE_REPORTS = "scopeGroupYoutubeReports";
	public const SCOPE_GROUP_YOUTUBE_QUERIES = "scopeGroupYoutubeQueries";
	
	public $youtubeScopeGroups = [
			self::SCOPE_GROUP_YOUTUBE_DATA_DEVICE => [
					self::SCOPE_YOUTUBE,
					self::SCOPE_YOUTUBE_READONLY,
					self::SCOPE_YOUTUBE_UPLOAD
			],
			self::SCOPE_GROUP_YOUTUBE_DATA => [
					self::SCOPE_YOUTUBE,
					self::SCOPE_YOUTUBE_READONLY,
					self::SCOPE_YOUTUBE_UPLOAD,
					self::SCOPE_YOUTUBE_FORCESSL
			],
			self::SCOPE_GROUP_YOUTUBE_REPORTS => [
					self::SCOPE_YOUTUBE_ANALYTICS_MONETARY_READONLY,
					self::SCOPE_YOUTUBE_ANALYTICS_READONLY
			],
			self::SCOPE_GROUP_YOUTUBE_QUERIES => [
					self::SCOPE_YOUTUBE_ANALYTICS_MONETARY_READONLY,
					self::SCOPE_YOUTUBE_ANALYTICS_READONLY,
					self::SCOPE_YOUTUBE,
					self::SCOPE_YOUTUBE_PARTNER
			]
	];
	
	private $userAccountEmail;
	private $projectName;
	private $projectRowId;
	private $ytAccountRowId;
	private $userId;
	private $channelId;
	private $channelTitle;
	
	/**
	 * @var \Doctrine\DBAL\Connection
	 */
	private $conn;
	
	/**
	 * Optional console output interface to be used for error/debug output
	 *
	 * @var OutputInterface
	 */
	private $output;
	
	/**
	 * Key:  clientType
	 * Value:  array containing keys:  'access_token_id', 'youtube_service'
	 */
	private $oauthClientYouTubeServices = [];
	private $serviceAccountYouTubeService = null;
	
	private $quotaExceeded = null;
	private $uploadLimitExceeded = null;
	
	private $isCommandLine = true;
	private $redirectUri = self::DEFAULT_REDIRECT_URI;
	
	/**
	 * Initializes a new <tt>YouTubeServiceBuilder</tt>.
	 * 
	 * @param string $userAccountEmail Google user account email address used to log in such as 'jfmediaqa@gmail.com '
	 * @param string $projectName Google API project name such as 'Conversation Starters Project'
	 * @param string $channelTitle YouTube channel for which to build clients + services
	 * @param \Doctrine\DBAL\Connection $conn
	 * @param OutputInterface $output
	 */
	public function __construct($userAccountEmail, $projectName, $channelTitle, \Doctrine\DBAL\Connection $conn, OutputInterface $output = null)
	{
		$this->userAccountEmail = $userAccountEmail;
		$this->projectName = $projectName;
		$this->channelTitle = $channelTitle;
		$this->conn = $conn;
		$this->output = $output ?? new NullOutput();
		
		$googleApiProject = $this->getGoogleApiProjectFromDB();
		if (is_null($googleApiProject)) {
			throw new \Exception("Invalid Google api project name [$projectName] for user account [$userAccountEmail]");
		} 
		
		$this->projectRowId = $googleApiProject['id'];
		
		$account = $this->getUserChannelIdFromDB();
		if (is_null($account)) {
			throw new \Exception("Invalid Google YouTube channel title [$channelTitle] provided for project name [$projectName] and user account [$userAccountEmail]");
		} 
		
		$this->ytAccountRowId = $account['id'];
		$this->userId = $account['userId'];
		$this->channelId = $account['channelId'];
		
		$this->reset();
	}
	
	public function setIsCommandLine($isCommandLine = true) {
		$this->isCommandLine = $isCommandLine;
	}
	
	public function setRedirectUri($redirectUri) {
		$this->redirectUri = $redirectUri;
	}
	
	public function getUserId()
	{
		return $this->userId;
	}
	
	public function getChannelId()
	{
		return $this->channelId;
	}
	
	/**
	 * Call this for instance if you are changing the set of 'scopes' accessible
	 * via a specific oauth client access token.
	 */
	public function forceReauthenticate()
	{
		$oauthClients = $this->getOauthClientsFromDB();
		foreach ($oauthClients as $oauthClient) {
			if ($oauthClient['type'] == self::YOUTUBE_CLIENT_TYPE_WEB) {
				
				$accessTokenInfo['access_token'] = null;
				$accessTokenInfo['refresh_token'] = null;
				$accessTokenInfo['expires_in'] = null;
				$accessTokenInfo['token_type'] = null;
				$accessTokenInfo['created'] = null;
				
				$this->updateOauthAccessTokenInDB($oauthClient['access_token_id'], $accessTokenInfo, [], $this->conn);
			}
		}
	}
	
	public function reset() 
	{
		$this->oauthClientYouTubeServices = [];
		$this->serviceAccountYouTubeService = null;
		
		$this->pullLimitsExceededAt();
		
		return $this;
	}
	
	public function getAuthorizedYouTubeDataApiKeyService()
	{
		$apiKeyInfo = $this->getApiKeyInfoFromDB();
		return self::createYouTubeApiKeyService($apiKeyInfo);
	}
	
	public function getAuthorizedYouTubeDataDeviceService($forceAccessTokenRefresh = false)
	{
		$scopes = $this->youtubeScopeGroups[self::SCOPE_GROUP_YOUTUBE_DATA_DEVICE];
		return $this->getAuthorizedYouTubeServiceForType(self::YOUTUBE_CLIENT_TYPE_OTHER, $scopes, $forceAccessTokenRefresh);
	}
	
	public function getAuthorizedYouTubeDataService($forceAccessTokenRefresh = false)
	{
		$scopes = $this->youtubeScopeGroups[self::SCOPE_GROUP_YOUTUBE_DATA];
		return $this->getAuthorizedYouTubeServiceForType(self::YOUTUBE_CLIENT_TYPE_WEB, $scopes, $forceAccessTokenRefresh);
	}
	
	public function getAuthorizedYouTubeReportService($forceAccessTokenRefresh = false)
	{
		$scopes = $this->youtubeScopeGroups[self::SCOPE_GROUP_YOUTUBE_REPORTS];
		return $this->getAuthorizedYouTubeServiceForType(self::YOUTUBE_CLIENT_TYPE_WEB, $scopes, $forceAccessTokenRefresh);
	}
	
	public function getAuthorizedYouTubeQueryService($forceAccessTokenRefresh = false)
	{
		$scopes = $this->youtubeScopeGroups[self::SCOPE_GROUP_YOUTUBE_QUERIES];
		return $this->getAuthorizedYouTubeServiceForType(self::YOUTUBE_CLIENT_TYPE_WEB, $scopes, $forceAccessTokenRefresh);
	}
	
	public function getAuthorizedYouTubeServiceForName($clientName, $oauthScopes, $forceAccessTokenRefresh)
	{
		$oauthClient = $this->getOauthAccessTokenFromDB(
				$this->userAccountEmail, 
				$this->projectName, 
				$this->channelTitle, 
				$clientName, 
				$this->conn);
		$youTubeService = $this->buildOauthClientYouTubeServiceForDB($oauthClient, $oauthScopes, $forceAccessTokenRefresh);
		
		return $youTubeService['youtube_service'];
	}
	
	public function getAuthorizedYouTubeServiceForType($clientType, $oauthScopes, $forceAccessTokenRefresh)
	{
		if ($clientType === self::YOUTUBE_CLIENT_TYPE_SERVICE) {
			
			if (is_null($this->serviceAccountYouTubeService)) {
				$this->serviceAccountYouTubeService = $this->buildServiceAccountYouTubeService();
			}
			
			return $this->serviceAccountYouTubeService;
		}
		
		$s = is_array($oauthScopes) ? implode(" ", $oauthScopes) : $oauthScopes;
		
		if (!array_key_exists($clientType, $this->oauthClientYouTubeServices) || 
				!array_key_exists($s, $this->oauthClientYouTubeServices[$clientType])) {
			$this->output->writeln("Building oauth client youtube service for client type [$clientType] for scopes [$s]");
			
			$this->oauthClientYouTubeServices[$clientType][$s] = $this->buildOauthClientYouTubeServiceForType($clientType, $oauthScopes, $forceAccessTokenRefresh);	
			
			if (is_null($this->oauthClientYouTubeServices[$clientType][$s])) {
				throw new \Exception("Missing Google oauth client type [$clientType][$s] for channel title [$this->channelTitle] and project name [$this->projectName]");
			} else if (false === $this->oauthClientYouTubeServices[$clientType][$s]) {
				throw new \Exception("User authorization cancelled");
			}
			
			$yt = $this->oauthClientYouTubeServices[$clientType][$s]['youtube_service'];
			if (!is_null($yt) && !empty($yt->getClient()->getAccessToken())) {
				return $yt;
			} else {
				return null;
			}
		}
		
		/**
		 * @var string $accessTokenId
		 */
		$accessTokenId = $this->oauthClientYouTubeServices[$clientType][$s]['access_token_id'];
		/**
		 * @var \Google_Service_YouTube $youTubeService
		 */
		$youTubeService = $this->oauthClientYouTubeServices[$clientType][$s]['youtube_service'];
		
		// Probably invalid clientType?
		if (is_null($youTubeService)) {
			return null;
		}

		$googleClient = $youTubeService->getClient();
		
		$existingScopes = $googleClient->getScopes();
		$missingScopes = YouTubeServiceBuilder::diffScopes($oauthScopes, $existingScopes);
		
		if (empty($googleClient->getAccessToken()) || 
				true === $googleClient->isAccessTokenExpired() || 
				true === $forceAccessTokenRefresh ||
				!empty($missingScopes)) {
					
			$refreshed = false;
			$oauthClient = null;
			
			if (!empty($accessTokenId)) {
								
				$oauthClient = $this->getOauthClientAccessTokenFromDBById($accessTokenId);
				
				if (empty($missingScopes)) {
					
					$this->output->writeln("Attempting to refresh already built youtube service of client type [$clientType]");
					$refreshed = $this->refreshOauthClientAccessToken($oauthClient, $youTubeService);
					
				}
				
			}
			
			if (false === $refreshed && $clientType === self::YOUTUBE_CLIENT_TYPE_WEB) {
				
				if (is_null($oauthClient)) {
					$clientName = $this->getOauthClientNameFromDB($clientType);
					
					// Build just enough of $oauthClient structure to allow
					// our interactive authorization method to work properly:
					$oauthClient = [ 
							'user_account_email' => $this->userAccountEmail,
							'project_name' => $this->projectName, 
							'channel_title' => $this->channelTitle, 
							'oauth_client_name' => $clientName ];
				}
				
				$this->output->writeln("Attempting to authorize already built youtube service of client type [$clientType] and name [".$oauthClient['oauth_client_name']."]");
				
				$this->oauthClientYouTubeServices[$clientType][$s]['access_token_id'] = 
					$this->obtainAuthorizationInteractively($oauthClient, $youTubeService);
			}
		}
		
		if (empty($youTubeService->getClient()->getAccessToken()) ||
				true === $youTubeService->getClient()->isAccessTokenExpired()) {
			
			$this->output->writeln("Attempted to refresh already built youtube service, but still not authorized!");
			return null;
		}
		
		$this->output->writeln("Returning previously authorized youtube service of client type [$clientType]");
		return $youTubeService;
	}
	
	/**
	 * Builds them all for a given channel title, returning an array of 
	 * Google_Service_YouTube using the type of service as key.  
	 * 
	 * @param boolean $forceAccessTokenRefresh
	 * @throws \Exception
	 * @return \Google_Service_YouTube[]
	 */
	public function buildYouTubeServices($forceAccessTokenRefresh = false)
	{
		$youTubeServices = [];
		
		$youTubeServices[self::YOUTUBE_CLIENT_TYPE_SERVICE] = $this->buildServiceAccountYouTubeService();
		
		$oauthClients = $this->getOauthClientsFromDB();
		foreach ($oauthClients as $oauthClient) {
			
			$this->output->writeln("Building oauth client youtube service for client type [".$oauthClient['type']."]");
			
			// For scopes, send null to indicate that we want to use what is
			// already in the DB
			$accessTokenIdAndYouTubeService = $this->buildOauthClientYouTubeServiceForDB($oauthClient, $forceAccessTokenRefresh, null);
			$youTubeServices[$oauthClient['type']] = $accessTokenIdAndYouTubeService['youtube_service'];

		}
		
		return $youTubeServices;	
	}
	
	/**
	 * Authenticates, creates a google client + YouTube service based on the
	 * [google_api_oauth_client] table that is related to a given user account's
	 * [google_api_project].
	 *
	 * For clients of type
	 * 1.  Oauth "Web application":  perform a user-interactive authentication that includes (if necessary)
	 * choosing a matching brand account (per incoming channelTitle)
	 * 2.  Oauth "Other":  an access_token + refresh_token must already be in google_api_oauth_access_token
	 * 3.  For service accounts, use the pre-configured service account credentials path
	 *
	 * @param string $clientType filter
	 * @param array $oauthScopes minimal scopes to include in an Oauth Google client (optional - may be null.  Doesn't apply to service accounts)
	 * @param boolean $forceAccessTokenRefresh (Doesn't apply to service accounts)
	 * @throws \Exception
	 * @return \Google_Service_YouTube
	 */
	public function buildYouTubeService($clientType = self::YOUTUBE_CLIENT_TYPE_WEB, $oauthScopes, $forceAccessTokenRefresh = false)
	{
		if ($clientType == self::YOUTUBE_CLIENT_TYPE_SERVICE) {
			$this->output->writeln("Building youtube service for client type [$clientType]");
			return $this->buildServiceAccountYouTubeService();
		}
		
		$this->output->writeln("Building oauth client youtube service for client type [$clientType]");
		
		$accessTokenIdAndYouTubeService = $this->buildOauthClientYouTubeServiceForType($clientType, $oauthScopes, $forceAccessTokenRefresh);
		return $accessTokenIdAndYouTubeService['youtube_service'];
	}
	
	protected function buildOauthClientYouTubeServiceForType($clientType, $scopes, $forceAccessTokenRefresh = false)
	{
		$accessTokenIdAndYouTubeService = null;
//		$candidateMissingScopes = null;
		
		$oauthClients = $this->getOauthClientsFromDB();
		
		foreach ($oauthClients as $oauthClient) {
			if ($oauthClient['type'] != $clientType) {
				continue;
			}
			
			// Commented this out, so that if scopes are different, we reauthenticate using
			// the new set of scopes:
//			if (!empty($oauthClient['scopes']) && !empty(YouTubeServiceBuilder::diffScopes($scopes, $oauthClient['scopes']))) {
//				$candidateMissingScopes = $oauthClient;
//				continue;
//			}
			
			$accessTokenIdAndYouTubeService = $this->buildOauthClientYouTubeServiceForDB($oauthClient, $scopes, $forceAccessTokenRefresh);
			break;
		}
		
		if (is_null($accessTokenIdAndYouTubeService)) {
//			if (!is_null($candidateMissingScopes)) {
				
				// Clear out access token info:
//				$oauthClient['access_token_id'] = null;
//				$oauthClient['auth_code'] = null;
//				$oauthClient['access_token'] = null;
//				$oauthClient['token_type'] = null;
//				$oauthClient['expires_in'] = null;
//				$oauthClient['created'] = null;
//				$oauthClient['refresh_token'] = null;
//				$oauthClient['scopes'] = null;
//				
//				$accessTokenIdAndYouTubeService = $this->buildOauthClientYouTubeServiceForDB($oauthClient, $scopes, $forceAccessTokenRefresh);
//			} else {
				$this->output->writeln("\t No oauth client found for scopes [".implode(',', $scopes)."].  Need to set up another [$clientType] oauth client");
//			}
		}
		return $accessTokenIdAndYouTubeService;
	}
	
	protected function buildOauthClientYouTubeServiceForDB($oauthClient, $scopes, $forceRefresh)
	{
		$accessTokenIdAndYouTubeService = null;
		
		if ($oauthClient['type'] == self::YOUTUBE_CLIENT_TYPE_WEB) {
			
			$accessTokenIdAndYouTubeService = $this->buildWebOauthClientYouTubeService($oauthClient, $scopes, $forceRefresh);
			
		} else if ($oauthClient['type'] == self::YOUTUBE_CLIENT_TYPE_OTHER) {
			
			$accessTokenIdAndYouTubeService = $this->buildOtherOauthClientYouTubeService($oauthClient, $scopes, $forceRefresh);
			
		} else {
			
			throw new \Exception("Oauth client type [".$oauthClient['type']."] not implemented!");
			
		}
		
		return $accessTokenIdAndYouTubeService;
	}
	
	/**
	 * Creates a Google client + YouTube service based on info in the
	 * [google_api_service_account] table that is related to the given
	 * user account's [google_api_project].  
	 * 
	 * There should only be a
	 * single service account configured for a given user account, which
	 * should include the appropriate JSON credentials file per Google
	 * documentation.
	 *
	 * @return NULL|\Google_Service_YouTube
	 */
	public function buildServiceAccountYouTubeService()
	{
		$serviceAccount = $this->getServiceAccountFromDB();
		if (is_null($serviceAccount)) {
			return null;
		}
		
		$pathToCredentials = $serviceAccount['path_to_credentials'];
		putenv("GOOGLE_APPLICATION_CREDENTIALS=$pathToCredentials");
		
		//$this->output->writeln("Putenv(GOOGLE_APPLICATION_CREDENTIALS):  ".getenv("GOOGLE_APPLICATION_CREDENTIALS"));
		
		$googleClient = new \Google_Client();
		$googleClient->useApplicationDefaultCredentials();
		
		$youTubeService = new \Google_Service_YouTube($googleClient);
		
		$this->output->writeln("Finished creating youtube service account for name [".$serviceAccount['account_name']."] and user [".$serviceAccount['user_account']."]");
		return $youTubeService;
	}
	
	protected function buildOtherOauthClientYouTubeService($oauthClient, $scopes, $forceRefresh)
	{
		$this->output->writeln("\t Creating [".$oauthClient['type']."] oauth youtube service for channel [".$oauthClient['channel_title']."] and client [".$oauthClient['oauth_client_name']."]");
		
		if (!empty(YouTubeServiceBuilder::diffScopes($scopes, $oauthClient['scopes']))) {
			throw new \Exception("Unable to change scopes of 'device' oauth client - existing [".implode(',', $oauthClient['scopes'])."] vs requested [".implode(',', $scopes)."]");
		}
		
		$youTubeService = YouTubeServiceBuilder::createYouTubeService($oauthClient, $this->redirectUri);
		
		$secondsTillExpire = 0;
		if (!empty($oauthClient['access_token'])) {
			$secondsTillExpire = $this->calcSecondsUntilExpiration($oauthClient['created'], $oauthClient['expires_in']);
		}
		
		if (empty($oauthClient['access_token']) || 0 >= $secondsTillExpire || true == $forceRefresh) {
			
			if (!array_key_exists('refresh_token', $oauthClient)) {
				throw new \Exception("Unable to refresh our [".$oauthClient['type']."] oauth access token due to missing 'refresh_token'");
			}
			
			$this->refreshOauthClientAccessToken($oauthClient, $youTubeService);
			
		} else {
			
			$this->output->writeln("\t Using existing [".$oauthClient['type']."] access token with id [".$oauthClient['access_token_id']."] - hasn't expired yet (Expires in [$secondsTillExpire] seconds)");

			$accessTokenInfo = $this->extractAccessTokenInfo($oauthClient);
			$youTubeService->getClient()->setAccessToken($accessTokenInfo);
			
		}
		
		if (!is_null($youTubeService)) {
			$this->output->writeln("\t Finished creating [".$oauthClient['type']."] oauth youtube service for channel [".$oauthClient['channel_title']."] and client [".$oauthClient['oauth_client_name']."]");
		}
		
		return [ 'access_token_id' => $oauthClient['access_token_id'], 'youtube_service' => $youTubeService ];
	}
	
	protected function buildWebOauthClientYouTubeService($oauthClient, $scopes, $forceRefresh)
	{
		$this->output->writeln("\t Creating [".$oauthClient['type']."] oauth youtube service for channel [".$oauthClient['channel_title']."] and client [".$oauthClient['oauth_client_name']."]");
		
		$youTubeService = YouTubeServiceBuilder::createYouTubeService($oauthClient, $this->redirectUri);
		$googleClient = $youTubeService->getClient();
		
		$accessTokenId = array_key_exists('access_token_id', $oauthClient) ? $oauthClient['access_token_id'] : null;
		
		$accessToken = $oauthClient['access_token'];
		$created = $oauthClient['created'];
		$expiresInSeconds = $oauthClient['expires_in'];
		$refreshToken = $oauthClient['refresh_token'];
		
		$needToAuth = false;
		
		// Decide on scopes:
		if (empty($scopes) && empty($oauthClient['scopes'])) {
			
			// use default and reauthenticate since we don't know what was there
			$googleClient->setScopes($this->youtubeScopeGroups[self::SCOPE_GROUP_YOUTUBE_DATA]);
			
			$needToAuth = true;
			$accessToken = null;
			$refreshToken = null;
			
		} else if (!empty($scopes)) {
			
			$googleClient->setScopes($scopes);
			
			$diff = YouTubeServiceBuilder::diffScopes($scopes, $oauthClient['scopes']);
			if (!empty($diff)) {
				
				// reauthenticate if something changed scope-wise:
				$needToAuth = true;
				$accessToken = null;
				$refreshToken = null;
				
			} 
		}
		
		if (!is_null($accessToken)) {
			
			$secondsTillExpire = $this->calcSecondsUntilExpiration($created, $expiresInSeconds);
			
			if ($secondsTillExpire > 0 && false == $forceRefresh) {
				
				$this->output->writeln("\t Using existing [".$oauthClient['type']."] access token with id [".$oauthClient['access_token_id']."] - hasn't expired yet (Expires in [$secondsTillExpire] seconds)");

				$accessTokenInfo = $this->extractAccessTokenInfo($oauthClient);
				$googleClient->setAccessToken($accessTokenInfo);
				
			} else {
				
				if ($secondsTillExpire <= 0) {
					$this->output->writeln("\t Access token expired [".(-$secondsTillExpire)."] seconds ago. Getting new one...");
				} else {
					$this->output->writeln("\t Forced refresh of access token that was set to expire in [".$secondsTillExpire."] seconds. Getting new one...");
				}
				
				$needToAuth = true;
			}
		} else {
			$needToAuth = true;
		}
		
		if (true === $needToAuth && !is_null($refreshToken)) {
			$needToAuth = !$this->refreshOauthClientAccessToken($oauthClient, $youTubeService);
		}
		
		if (true === $needToAuth) {	
			$accessTokenId = $this->obtainAuthorizationInteractively($oauthClient, $youTubeService);
			if (is_null($accessTokenId)) {
				return false;
			}
		}
		
		if (!is_null($youTubeService)) {
			$this->output->writeln("\t Finished creating [".$oauthClient['type']."] oauth youtube service for channel [".$oauthClient['channel_title']."] and client [".$oauthClient['oauth_client_name']."]");
		}
		
		return [ 'access_token_id' => $accessTokenId, 'youtube_service' => $youTubeService ];
	}
	
	protected function refreshOauthClientAccessToken($oauthClient, \Google_Service_YouTube $youTubeService)
	{
		if (!isset($oauthClient['refresh_token'])) {
			$this->output->writeln("\t No refresh_token with which to refresh [".$oauthClient['type']."] oauth access token having id [".$oauthClient['access_token_id']."]");
			return false;
		}
		
		$refreshToken = $oauthClient['refresh_token'];
		$this->output->writeln("\t Refreshing [".$oauthClient['type']."] oauth access token having id [".$oauthClient['access_token_id']."]");
		
		$googleClient = $youTubeService->getClient();
		
		$refreshResponse = $googleClient->refreshToken($refreshToken);
		
		if (array_key_exists('access_token', $refreshResponse)) {
			
			$googleClient->setAccessToken($refreshResponse);
			$this->updateOauthAccessTokenInDB($oauthClient['access_token_id'], $refreshResponse, $youTubeService->getClient()->getScopes(), $this->conn);
			
			$this->output->writeln("\t Refreshed [".$oauthClient['type']."] oauth access token having id [".$oauthClient['access_token_id']."]");
			
			return true;
			
		} else {

			$this->output->writeln("\t No access_token returned during refresh of [".$oauthClient['type']."] oauth access token having id [".$oauthClient['access_token_id']."]");
			return false;
			
		}
		
	}
	
	protected function obtainAuthorizationInteractively($oauthClient, \Google_Service_YouTube $youTubeService)
	{
		$googleClient = $youTubeService->getClient();
		
		$googleClient->setPrompt('consent');
		$authUrl = $googleClient->createAuthUrl();
		
		if ($this->isCommandLine === false) {
			$this->initializeOauthAccessTokenInDB($oauthClient, $googleClient->getRedirectUri(), $googleClient->getScopes());
			throw new InteractiveAuthorizationRequiredException($authUrl);
		}
		
		$this->output->writeln("\t Auth URL:  [$authUrl]");
		$this->output->writeln("\t Type 'yes' or 'y' to continue after auth is complete: ");
		
		$handle = fopen("php://stdin","r"); // read from STDIN
		$line = trim(fgets($handle));
		
		if ($line !== 'yes' && $line !== 'y') {
			$this->output->writeln("Cancelling user authorization...");
			return null;
		}
		
		$accessTokenId = YouTubeServiceBuilder::completeAuthentication($oauthClient, null, $this->conn, $youTubeService);
		return $accessTokenId;
	}

	protected static function createYouTubeApiKeyService(array $apiKeyInfo): \Google_Service_YouTube
	{
		$googleClient = new \Google_Client();
		$googleClient->setAccessType("offline");
		$googleClient->setDeveloperKey($apiKeyInfo['key']);
		
		$youTubeService = new \Google_Service_YouTube($googleClient);

		return $youTubeService;		
	}
	
	/**
	 * @param array $oauthClient (result of getOauthClientsFromDB or getOauthAccessTokenFromDB)
	 * @param string $redirectUri
	 * @return \Google_Service_YouTube
	 */
	protected static function createYouTubeService($oauthClient, $redirectUri = null)
	{
		$googleClient = new \Google_Client();
		$googleClient->setClientId($oauthClient['client_id']);
		$googleClient->setClientSecret($oauthClient['client_secret']);
		$googleClient->setAccessType("offline");
		$googleClient->setState($oauthClient['user_account_email']."|".$oauthClient['project_name']."|".$oauthClient['channel_title']."|".$oauthClient['oauth_client_name']);
		if (!empty($oauthClient['scopes'])) {
			$googleClient->setScopes($oauthClient['scopes']);
		}
		if (!is_null($redirectUri)) {
			$googleClient->setRedirectUri($redirectUri);
		} else if (isset($oauthClient['redirect_uri'])) {
			$googleClient->setRedirectUri($oauthClient['redirect_uri']);
		}
		
		$youTubeService = new \Google_Service_YouTube($googleClient);
		return $youTubeService;
	}
	
	public static function completeAuthentication($oauthClient, $authCode, \Doctrine\DBAL\Connection $conn, \Google_Service_YouTube $youTubeService = null) {
		
		// Assumes it was just updated with a new Auth code
		$token = YouTubeServiceBuilder::getOauthAccessTokenFromDB(
				$oauthClient['user_account_email'],
				$oauthClient['project_name'], 
				$oauthClient['channel_title'], 
				$oauthClient['oauth_client_name'], 
				$conn);
		$authCode = $authCode ?? $token['auth_code'];
		
		if (is_null($youTubeService)) {
			// We are probably being called by CallbackController.  Therefore, "initializeOauthAccessTokenInDB"
			// should have been called earlier setting scopes and redirect_uri for our DB access token row:
			$youTubeService = YouTubeServiceBuilder::createYouTubeService($token, $token['redirect_uri']);
		}
		
		$googleClient = $youTubeService->getClient();
		
//		$output->writeln("\t Fetching [".$token['type']."] oauth access token (having id [".$token['access_token_id']."]) with auth code");
		
		$accessTokenInfo = $googleClient->fetchAccessTokenWithAuthCode($authCode);
		$googleClient->setAccessToken($accessTokenInfo);
		
		// TODO:  or should the scopes be added by the callback handler?
		YouTubeServiceBuilder::updateOauthAccessTokenInDB($token['access_token_id'], $accessTokenInfo, $googleClient->getScopes(), $conn);
		
		return $token['access_token_id'];
	}
	
	public function getQuotaExceeded() 
	{
		$today = (new \DateTime())->setTime(0, 0);
		
		if (isset($this->quotaExceeded)) {
			$db = clone $this->quotaExceeded;
			if ($today->diff($db->setTime(0, 0))->days > 0) {
				// If there is a midnight between now and when the quota was exceeded:
				$this->resetQuotaExceeded();
			}
		}
		
		return $this->quotaExceeded;
	}
	
	public function getUploadLimitExceeded()
	{
		$today = (new \DateTime())->setTime(0, 0);
		
		if (isset($this->uploadLimitExceeded)) {
			$db = clone $this->uploadLimitExceeded;
			if ($today->diff($db->setTime(0, 0))->days > 0) {
				// If there is a midnight between now and when the limit was exceeded:
				$this->resetUploadLimitExceeded();
			}
		}
		
		return $this->uploadLimitExceeded;
	}
	
	public function resetQuotaExceeded()
	{
		$this->quotaExceeded = $this->setLimitExceededAt('quota_exceeded_at', $this->quotaExceeded, true);
	}
	
	public function notifyQuotaExceeded()
	{
		$this->quotaExceeded = $this->setLimitExceededAt('quota_exceeded_at', $this->quotaExceeded, false);
	}
	
	public function resetUploadLimitExceeded()
	{
		$this->uploadLimitExceeded = $this->setLimitExceededAt('upload_limit_exceeded_at', $this->uploadLimitExceeded, true);
	}

	public function notifyUploadLimitExceeded()
	{
		$this->uploadLimitExceeded = $this->setLimitExceededAt('upload_limit_exceeded_at', $this->uploadLimitExceeded, false);
	}
	
	protected function setLimitExceededAt($column, $currValue, $reset = false)
	{
		$nullCheck = (false === $reset) ? "AND gap.$column IS NULL" : "";
		$atValue = (false === $reset) ? new \DateTime() : null;
		
		$query =
			"UPDATE google_api_project gap
			 SET
				gap.$column = :atValue
			 WHERE gap.id = :projectRowId
			 $nullCheck";
		
		$params = array(
			"projectRowId" => $this->projectRowId,
			"atValue" => $atValue
		);
		$types = array (
			"atValue" => "datetime"
		);
		DatabaseUtils::makeStatementQuery($this->conn, $query, $params, $types);
		
		if ($currValue instanceof \DateTime && $atValue instanceof \DateTime) {
			return $currValue;
		} else {
			return $atValue;
		}
	}
	
	protected function pullLimitsExceededAt()
	{
		$query =
			"SELECT quota_exceeded_at, upload_limit_exceeded_at
			 FROM google_api_project gap 
			 WHERE gap.id = :projectRowId";
		
		$params = array(
			"projectRowId" => $this->projectRowId
		);
		$results = DatabaseUtils::makeSelectQueryFetchAll($this->conn, $query, $params);
				
		if (count($results) == 1) {
			$this->quotaExceeded = null;
			$this->uploadLimitExceeded = null;
			
			if (isset($results[0]['quota_exceeded_at'])) {
				$this->quotaExceeded = \DateTime::createFromFormat("Y-m-d H:i:s", $results[0]['quota_exceeded_at']);
			}
			if (isset($results[0]['upload_limit_exceeded_at'])) {
				$this->uploadLimitExceeded = \DateTime::createFromFormat("Y-m-d H:i:s", $results[0]['upload_limit_exceeded_at']);
			}
			
		} else {
			throw new \Exception("Unexpected [".count($results)."] number of Google YouTube account records match user account [$this->userAccount] and channel title [$this->channelTitle]");
		}		
	}
	
	public function getGoogleApiProjectFromDB()
	{
		$query =
			"SELECT gap.*
			 FROM google_api_project gap
			 INNER JOIN google_user_account gua on gua.id = gap.google_user_account_id
			 WHERE gap.project_name = :projectName
			 AND gua.user_account_email = :userAccountEmail";
		
		$params = array(
			"userAccountEmail" => $this->userAccountEmail,
			"projectName" => $this->projectName
		);
		$results = DatabaseUtils::makeSelectQueryFetchAll($this->conn, $query, $params);
		
		if (count($results) == 1) {
			return $results[0];
		}
		
		if (count($results) == 0) {
			return null;
		}
		
		throw new \Exception("More than one Google api project record matches project name [$this->projectName] and user account [$this->userAccountEmail]");
	}
	
	public function getUserChannelIdFromDB()
	{
		$query =
			"SELECT gya.id, user_id, channel_id
			 FROM google_youtube_account gya
			 INNER JOIN google_user_account gua on gua.id = gya.google_user_account_id
			 INNER JOIN google_api_project gap on gua.id = gap.google_user_account_id
			 WHERE gap.id = :projectRowId
			 AND gya.channel_title = :channelTitle";
		

		$params = array(
			"projectRowId" => $this->projectRowId,
			"channelTitle" => $this->channelTitle
		);
		$results = DatabaseUtils::makeSelectQueryFetchAll($this->conn, $query, $params);
		
		if (count($results) == 1) {
			return [ 'id' => $results[0]['id'], 'userId' => $results[0]['user_id'], 'channelId' => $results[0]['channel_id'] ];
		}
		
		if (count($results) == 0) {
			return null;
		}
		
		throw new \Exception("More than one Google YouTube account record matches project row ID [$this->projectRowId] and channel title [$this->channelTitle]");
	}
	
	protected function getOauthClientNameFromDB($clientType)
	{
		$query =
			"SELECT goc.name as client_name
			 FROM google_api_oauth_client goc
			 INNER JOIN google_api_project gap on gap.id = goc.google_api_project_id
			 WHERE gap.id = :projectRowId
			 AND goc.type = :clientType";
		
		$params = array(
			"projectRowId" => $this->projectRowId,
			"clientType" => $clientType
		);
		$results = DatabaseUtils::makeSelectQueryFetchAll($this->conn, $query, $params);
		
		if (count($results) == 1) {
			return $results[0]['client_name'];
		}
		
		if (count($results) == 0) {
			return null;
		}
		
		throw new \Exception("More than one Google oauth client record matches project row ID [$this->projectRowId] and client type [$clientType]");
	}
	
	protected function initializeOauthAccessTokenInDB($oauthClient, $redirectUri, array $scopes = [])
	{
		$oauthAccessTokenId = isset($oauthClient['access_token_id']) ? $oauthClient['access_token_id'] : null;
		$params = array();
		if (is_null($oauthAccessTokenId)) {
			
			// Insert query
			$query = "
				INSERT INTO google_api_oauth_access_token
					(google_api_oauth_client_id, google_youtube_account_id, scopes, redirect_uri)
				SELECT
					(:oauthClientRowId),
					(:youTubeAccoutRowId),
					(:scopes),
					(:redirectUri)";
			
			$params["oauthClientRowId"] = $oauthClient['client_row_id'];
			$params["youTubeAccoutRowId"] = $oauthClient['account_row_id'];
		} else {
			
			// Update query
			$query = "
					UPDATE google_api_oauth_access_token
					SET
						scopes = :scopes,
						redirect_uri = :redirectUri
					WHERE id = :id";
			
			$params["id"] = $oauthAccessTokenId;
		}
		
		$params["redirectUri"] = $redirectUri;
		if (!empty($scopes)) {
			$params["scopes"] = implode(' ', $scopes);
		} else {
			$params["scopes"] = null;
		}

		DatabaseUtils::makeStatementQuery($this->conn, $query, $params);
		
		if (is_null($oauthAccessTokenId)) {
			$oauthAccessTokenId = $this->conn->lastInsertId();
		}
		
		return $oauthAccessTokenId;
	}
	
	protected static function updateOauthAccessTokenInDB($id, $accessTokenInfo, array $scopes = [], \Doctrine\DBAL\Connection $conn)
	{
		
		// setting auth code to NULL since it has already been redeemed
		$query =
			"UPDATE google_api_oauth_access_token t
			 SET
				access_token = :accessToken,
				token_type = :tokenType,
				expires_in = :expiresIn,
				created = :created,
				refresh_token = :refreshToken,
				auth_code = NULL,
				scopes = :scopes
			WHERE id = :id";
		
		$params = array(
			"id" => $id,
			"accessToken" => isset($accessTokenInfo['access_token']) ? $accessTokenInfo['access_token'] : null,
			"tokenType" => isset($accessTokenInfo['token_type']) ? $accessTokenInfo['token_type'] : null,
			"expiresIn" => isset($accessTokenInfo['expires_in']) ? $accessTokenInfo['expires_in'] : null,
		);
		$types = array();
		if (isset($accessTokenInfo['created'])) {
			$params["created"] = \DateTime::createFromFormat('U', $accessTokenInfo['created']);
			$types["created"] = "datetime";
		} else {
			$params["created"] = null;
		}
		
		// Only shows up if prompt='consent' when doing auth?
		if (array_key_exists('refresh_token', $accessTokenInfo)) {
			$params["refreshToken"] = $accessTokenInfo['refresh_token'];
		} else {
			$params["refreshToken"] = null;
		}
		
		if (!empty($scopes)) {
			$params["scopes"] = implode(' ', $scopes);
		} else {
			$params["scopes"] = null;
		}
		
		DatabaseUtils::makeStatementQuery($conn, $query, $params, $types);
		
	}
	
	protected static function getOauthAccessTokenFromDB(
			string $userAccountEmail, 
			string $projectName, 
			string $channelTitle, 
			string $oauthClientName, 
			\Doctrine\DBAL\Connection $conn)
	{
		$query =
				"SELECT 
					gua.user_account_email,
					gap.project_name, 
					gya.id as account_row_id, gya.channel_title,
					goc.id as client_row_id, goc.name as oauth_client_name, goc.type, goc.client_id, goc.client_secret,
					gat.id as access_token_id, gat.auth_code, gat.access_token, gat.token_type, gat.expires_in, gat.created, gat.refresh_token, gat.scopes, gat.redirect_uri
				 FROM google_api_oauth_access_token gat
				 INNER JOIN google_youtube_account gya on gat.google_youtube_account_id = gya.id
				 INNER JOIN google_api_oauth_client goc on gat.google_api_oauth_client_id = goc.id
				 INNER JOIN google_api_project gap on goc.google_api_project_id = gap.id
				 INNER JOIN google_user_account gua on gya.google_user_account_id = gua.id
				 WHERE gya.channel_title = :channelTitle
				 AND goc.name = :oauthClientName
				 AND gap.project_name = :projectName
				 AND gua.user_account_email = :userAccountEmail";
		

		$params = array(
			"userAccountEmail" => $userAccountEmail,
			"projectName" => $projectName,
			"channelTitle" => $channelTitle,
			"oauthClientName" => $oauthClientName
		);

		$results = DatabaseUtils::makeSelectQueryFetchAll($conn, $query, $params);
		
		if (count($results) == 1) {
			return $results[0];
		}
		
		if (count($results) == 0) {
			return null;
		}
		
		throw new \Exception("More than one Google oauth access token record matches channel title [$channelTitle] and oauth client name [$oauthClientName]");
	}
	
	protected function extractAccessTokenInfo(array $oauthClient)
	{
		$accessTokenInfo = [];
		
		$accessTokenInfo['access_token'] = isset($oauthClient['access_token']) ? $oauthClient['access_token'] : null;
		$accessTokenInfo['token_type'] = isset($oauthClient['token_type']) ? $oauthClient['token_type'] : null;
		$accessTokenInfo['expires_in'] = isset($oauthClient['expires_in']) ? intval($oauthClient['expires_in']) : null;
		
		if (isset($oauthClient['refresh_token'])) {
			$accessTokenInfo['refresh_token'] = $oauthClient['refresh_token'];
		}
		
		if (isset($oauthClient['created'])) {
			$createdDt = \DateTime::createFromFormat("Y-m-d H:i:s", $oauthClient['created'], new \DateTimeZone('UTC'));
			$accessTokenInfo['created'] = $createdDt->getTimestamp();
		}
		
		return $accessTokenInfo;
	}
	
	protected function getOauthClientAccessTokenFromDBById($accessTokenId)
	{
		$query =
			"SELECT
				gua.user_account_email,
				gap.project_name,
				gya.id as account_row_id, gya.channel_id, gya.channel_title,
				goc.id as client_row_id, goc.name as oauth_client_name, goc.type, goc.client_id, goc.client_secret,
				gat.id as access_token_id, gat.auth_code, gat.access_token, gat.token_type, gat.expires_in, gat.created, gat.refresh_token, gat.scopes, gat.redirect_uri
			 FROM google_api_oauth_access_token gat 
			 INNER JOIN google_api_oauth_client goc on goc.id = gat.google_api_oauth_client_id
			 INNER JOIN google_api_project gap on gap.id = goc.google_api_project_id
			 INNER JOIN google_youtube_account gya on gya.id = gat.google_youtube_account_id
			 INNER JOIN google_user_account gua on gya.google_user_account_id = gua.id
			 WHERE gat.id = :id";
		

		$params = array(
			"id" => $accessTokenId
		);

		$results = DatabaseUtils::makeSelectQueryFetchAll($this->conn, $query, $params);
		
		if (count($results) == 1) {
			return $results[0];
		}
		
		throw new \Exception("Google oauth client access token record not found for id [$accessTokenId]");
	}
	
	protected function getOauthClientsFromDB()
	{
		// Note that the column "access_token_id" is the google_api_oauth_access_token row id
		// The combination of LEFT JOIN and the last AND clause are meant to return oauth access
		// tokens for devices ('Other') that already have an access token row, and for Web
		// applications whether or not they have access token row yet (since the latter is
		// generated via some user interaction while this command runs).
		$query =
			"SELECT
				gua.user_account_email,
				gap.project_name,
				gya.id as account_row_id, gya.channel_id, gya.channel_title,
				goc.id as client_row_id, goc.name as oauth_client_name, goc.type, goc.client_id, goc.client_secret,
				gat.id as access_token_id, gat.auth_code, gat.access_token, gat.token_type, gat.expires_in, gat.created, gat.refresh_token, gat.scopes, gat.redirect_uri
			 FROM google_api_oauth_client goc
			 INNER JOIN google_api_project gap on gap.id = goc.google_api_project_id
			 INNER JOIN google_user_account gua on gua.id = gap.google_user_account_id
			 INNER JOIN google_youtube_account gya on gya.google_user_account_id = gua.id
			 LEFT JOIN google_api_oauth_access_token gat on
				gat.google_api_oauth_client_id = goc.id AND
				gat.google_youtube_account_id = gya.id
			 WHERE gap.id = :projectRowId
			 AND gya.id = :ytAccountRowId
			 AND (	gya.primary_account_id IS NOT NULL AND goc.type != 'Other' OR
			 		gya.primary_account_id IS NULL)";
				
		$params = array(
			"projectRowId" => $this->projectRowId,
			"ytAccountRowId" => $this->ytAccountRowId
		);

		$results = DatabaseUtils::makeSelectQueryFetchAll($this->conn, $query, $params);
		
		return $results;
	}
	
	protected function getServiceAccountFromDB()
	{
		$query =
			"SELECT *
			 FROM google_api_service_account gsa
			 INNER JOIN google_api_project gap on gap.id = gsa.google_api_project_id
			 WHERE gap.id = :projectRowId";
		

		$params = array(
			"projectRowId" => $this->projectRowId
		);

		$results = DatabaseUtils::makeSelectQueryFetchAll($this->conn, $query, $params);
		
		if (count($results) == 1) {
			return $results[0];
		}
		
		if (count($results) == 0) {
			return null;
		}
		
		throw new \Exception("More than one Google service account record matches project row ID [$this->projectRowId]");
	}
	
	protected function getApiKeyInfoFromDB()
	{
		$query =
			"SELECT *
			 FROM google_api_key gak
			 INNER JOIN google_api_project gap on gap.id = gak.google_api_project_id
			 WHERE gap.id = :projectRowId";
		
		$params = array(
			"projectRowId" => $this->projectRowId
		);

		$results = DatabaseUtils::makeSelectQueryFetchAll($this->conn, $query, $params);
		
		if (count($results) == 1) {
			return $results[0];
		}
		
		if (count($results) == 0) {
			return null;
		}
		
		throw new \Exception("More than one Google api key record matches project row ID [$this->projectRowId]");
	}
	
	public static function receiveAuthCode(
			string $authCode, 
			string $userAccountEmail,
			string $projectName, 
			string $channelTitle, 
			string $oauthClientName, 
			\Doctrine\DBAL\Connection $conn, 
			OutputInterface $output)
	{
		$query = "
				SELECT goc.id as oauth_client_row_id, gya.id as youtube_account_row_id, gat.id as access_token_row_id
				FROM google_api_project gap
				INNER JOIN google_api_oauth_client goc on gap.id = goc.google_api_project_id
				INNER JOIN google_user_account gua on gua.id = gap.google_user_account_id
				INNER JOIN google_youtube_account gya on gua.id = gya.google_user_account_id
				LEFT JOIN google_api_oauth_access_token gat on gat.google_api_oauth_client_id = goc.id AND gat.google_youtube_account_id = gya.id
				WHERE gya.channel_title = :channelTitle
				AND goc.name = :oauthClientName
				AND gap.project_name = :projectName
				AND gua.user_account_email = :userAccountEmail";
		
		$params = array(
			"userAccountEmail" => $userAccountEmail,
			"projectName" => $projectName,
			"channelTitle" => $channelTitle,
			"oauthClientName" => $oauthClientName
		);

		$results = DatabaseUtils::makeSelectQueryFetchAll($conn, $query, $params);
		
		if (count($results) != 1) {
			$output->writeln("Invalid number of results [".count($results)."] when locating access token record with title [$channelTitle] and client name [$oauthClientName]");
		}
		
		$oauthClientRowId = $results[0]['oauth_client_row_id'];
		$youTubeAccoutRowId = $results[0]['youtube_account_row_id'];
		$accessTokenRowId = $results[0]['access_token_row_id'];
		
		if (is_null($accessTokenRowId)) {
			
			// Insert query
			$query = "
				INSERT INTO google_api_oauth_access_token
					(google_api_oauth_client_id, google_youtube_account_id, auth_code)
				SELECT
					($oauthClientRowId),
					($youTubeAccoutRowId),
					('$authCode')";

			DatabaseUtils::makeStatementQuery($conn, $query);
			$oauthAccessTokenId = $conn->lastInsertId();
			
			$output->writeln("Inserted auth code into google_api_oauth_access_token with id [$oauthAccessTokenId]");
			
		} else {
			
			// Update query
			$query = "
					UPDATE google_api_oauth_access_token
					SET auth_code = :authCode
					WHERE id = :id";
			
			$params = array(
				"authCode" => $authCode,
				"id" => $accessTokenRowId
			);
			$result = DatabaseUtils::makeStatementQuery($conn, $query, $params);
			
			$output->writeln("Updated record id [$accessTokenRowId] into google_api_oauth_access_token with auth code");
			
		}
	}
	
	public static function calcSecondsUntilExpiration($created, $expiresInSeconds)
	{
		if (!$created) {
			return 0;
		}
		
		$createdDt = \DateTime::createFromFormat("Y-m-d H:i:s", $created, new \DateTimeZone('UTC'));
		$currDt = new \DateTime('now', new \DateTimeZone('UTC'));
		
		$diffSeconds = $currDt->getTimestamp() - $createdDt->getTimestamp();
		
		$secondsTillExpire = $expiresInSeconds - $diffSeconds;
		
		return $secondsTillExpire;
	}
	
	
	/**
	 * Returns the scopes in $a that are not present in $b
	 * @param null|string|array $a
	 * @param null|string|array $b
	 */
	protected static function diffScopes($a, $b)
	{
		if (is_null($a)) {
			$a = array();
		} else if (is_string($a)) {
			$a = explode(" ", $a);
		}
		
		if (is_null($b)) {
			$b = array();
		} else if (is_string($b)) {
			$b = explode(" ", $b);
		}
		
		$diff = array_diff($a, $b);
		return $diff;
	}
	
}