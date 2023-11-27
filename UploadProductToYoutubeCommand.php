<?php

namespace Arclight\ImportBundle\Command\Social;

use Arclight\ImportBundle\Utils\ApiRequestBuilder;
use Arclight\ImportBundle\Utils\ArclightShareQueryHelper;
use Arclight\ImportBundle\Utils\YouTubeRequestBuilder;
use Arclight\ImportBundle\Utils\YouTubeServiceBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Arclight\MMDBBundle\php\misc\Parameters;
use Arclight\MMDBBundle\php\misc\Utils;
use Arclight\MMDBBundle\php\ovp\OvpStorageServiceInterface;
use Symfony\Component\Finder\Finder;

/**
 * UploadProductToYouTubeCommand
 *
 * Usage: bin/console import:uploadProductToYouTube jfmediaqa@gmail.com "JESUS Film":default CS1 529 21028 --uploadvideos
 *        bin/console import:uploadProductToYouTube jfpwebguy@gmail.com "Arclight-uploader-3":"Jesus Film Project" 1_jf-0-0 4415 --listallvideos
 *        bin/console import:uploadProductToYouTube jfpwebguy@gmail.com "Arclight-uploader-3":"Jesus Film Project" 1_jf-0-0 12551 --downloadvideos
 *        bin/console import:uploadProductToYouTube jfpwebguy@gmail.com "Arclight-uploader-3":"Jesus Film Project" 1_jf-0-0 12551 --uploadvideos --includeparentinchildrentitles --privacystatus=unlisted
 *        bin/console import:uploadProductToYouTube admin@morleyconsulting.net "YouTube API Tests":"default" 1_jf-0-0 12551 --listallvideos
 *        
 * Changes per Howard Crutsinger on 2020-06-02
 * 
 *  1.  bin/console import:uploadPlaylistsToYouTube jfpwebguy@gmail.com "Arclight-uploader-3":"Jesus Film Project" 1197 --parentmediacomponentid=1_jf-0-0 --allowemptyplaylists --privacystatus=unlisted
 *  2.  bin/console import:uploadProductToYouTube jfpwebguy@gmail.com "Arclight-uploader-3":"Jesus Film Project" 1_jf-0-0 1197 --uploadvideos --includeparentinchildrentitles --videolanguageoverride=1197:en --playlistid={{playlistId from previous command}} --privacystatus=unlisted
 *  3.  bin/console import:uploadPlaylistsToYouTube jfpwebguy@gmail.com "Arclight-uploader-3":"Jesus Film Project" 1197 --parentmediacomponentid=1_jf-0-0
 *  4.  bin/console import:uploadProductToYouTube jfpwebguy@gmail.com "Arclight-uploader-3":"Jesus Film Project" 1_jf-0-0 1197 --updatevideostatus --includeparentinchildrentitles --privacystatus=public
 *  5.  Use Youtube studio to update the privacy status of the playlist to "public" (until we add that to one of the commands)
 *  
 *  - English title format for 1_jf-0-0 (feature film):  
 *      Formula:  The JESUS Film • Official Full Feature Film • in {{LANG_NAME_EN}} voice
 *      Example:  The JESUS Film • Official Full Feature Film • in Hindi voice
 *  - English title format (parent/standalone):  
 *      Formula:  {{TITLE_EN}} • {{SUBTYPE_EN}} • {{LANG_NAME_NATIVE}} ({{LANG_NAME_EN}})
 *      Example:  My Last Day • Short Film • हिन्दी (Hindi)
 *  - Localized title format (parent/standalone):  
 *      Formula:  {{TITLE_LOCALE}} • {{SUBTYPE_LOCALE}} • {{LANG_NAME_NATIVE}} ({{LANG_NAME_EN}})
 *      Example:  ИИСУС • Художественный фильм • हिन्दी (Hindi)
 *      
 *  - English title format for 1_jf-0-0 (segment):
 *      Formula:  The JESUS Film clip • "{{CLIP_NAME_EN}}" • clip {{POSITION}} of {{TOTAL}} clips • in {{LANG_NAME_EN}} voice
 *      Example:  The JESUS Film clip • "The Devil Tempts Jesus" • clip 5 of 61 clips • in Hindi voice
 *  - English title format (segment/episode):  
 *      Formula:  {{PARENT_TITLE_EN}} • "{{TITLE_EN}}" • {{POSITION_OF_COUNT}} • {{LANG_NAME_NATIVE}} ({{LANG_NAME_EN}})
 *      Example: Magdalena • "Mary Magdalene goes to Rivka's house" • 2/44 • हिन्दी (Hindi)
 *  - Localized title format (segment/episode):  
 *      Formula:  {{PARENT_TITLE_LOCALE}} • {{TITLE_LOCALE}} • {{POSITION_OF_COUNT}} • {{LANG_NAME_NATIVE}} ({{LANG_NAME_EN}})
 *      Example:  Магдалена • "Мария Магдалина идет в дом Ривки" • 2/44 • हिन्दी (Hindi)
 *      
 *  - English description format for 1_jf-0-0 parents and children:
 *      Formula:  {{BOILERPLATE_PLAYLIST_EN}} \n\n {{DESCRIPTION_EN}} \n\n {{BOILERPLATE_AFTER_EN}}
 *  - Localized description format for 1_jf-0-0 parents and children:
 *      Formula:  {{BOILERPLATE_PLAYLIST_EN}} \n\n {{DESCRIPTION_LOCALE}} \n\n {{YOUTUBE_TITLE_EN}} \n\n {{BOILERPLATE_AFTER_EN}}
 *  - English description format for 1_wjv-0-0 parents and children:
 *      Formula:  {{DESCRIPTION_EN}} \n\n {{BOILERPLATE_AFTER_EN}}
 *  - Localized description format for 1_wjv-0-0 parents and children:
 *      Formula:  {{DESCRIPTION_LOCALE}} \n\n {{YOUTUBE_TITLE_EN}} \n\n {{BOILERPLATE_AFTER_EN}}
 *  - English description format (others):
 *      Formula:  {{DESCRIPTION_EN}}
 *  - Localized description format (others):
 *      Formula:  {{DESCRIPTION_LOCALE}} \n\n {{YOUTUBE_TITLE_EN}}
 */
class UploadProductToYouTubeCommand extends Command
{
	/*
	 * Device access_token retrieval:
	 * 1.  curl -d "client_id=29954156185-f517501cn3mk7diuuoimcqo473a2j01p.apps.googleusercontent.com&\
scope=https://www.googleapis.com/auth/youtube https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly" \
https://accounts.google.com/o/oauth2/device/code
	 * 2.  In browser, 
	 * 			navigate to:  https://www.google.com/device
	 * 			enter "user_code" returned in step 1
	 * 			choose "allow"
	 * 3.  curl -d "client_id=29954156185-f517501cn3mk7diuuoimcqo473a2j01p.apps.googleusercontent.com&client_secret=L_hEWQ3SkWAGqg25AiFPPtD3&\
code=AH-1Ng1JZaNHGnhg2jxvw037WTVd2_b8yp8dO0OQa5ex-n7NXNF2umI8Hb3eugKgXllCLGEQmaCM7VPPAgySIIyhLl6zMB8eEw&grant_type=http://oauth.net/grant_type/device/1.0" \
-H "Content-Type: application/x-www-form-urlencoded" https://www.googleapis.com/oauth2/v4/token
	 *
	 * 4.  Put access_token, refresh_token etc into DB
	 */
	const DEFAULT_ARCLIGHT_API_KEY = "5715236f730906.19676983";  //jf.org key
	const DEFAULT_USER_ACCOUNT = 'jfmediaqa@gmail.com';
	const DEFAULT_CHANNEL_TITLE = 'default';
	
	const BASE_URI = "http://api.arclight.org";
	
	private $langToSubLang = [
	    12551 => [ 529 ],                          // Tagalog
	    6464  => [ 529, 6464 ],                    // Hindi => English, Hindi
	    16639 => [ 529, 16639 ],                   // Indonesian (Yesus) => English, Indonesian (Yesus)
	    13169 => [ 529 ],                          // Thai => English
	    20601 => [ 529, 21754, 21753 ],            // Cantonese => English, Chinese Simplified, Chinese Traditional
	    1927  => [ 529, 16639 ],                   // Malay => English, Indonesian (Yesus)
	    5871  => [ 529, 6464 ],                    // Tamil => English, Hindi
	];
	
	private $langToDescLang = [
	    12551 => [ "en" ],                         // Tagalog
	    6464  => [ "en", "hi" ],                   // Hindi => English, Hindi
	    16639 => [ "en", "id" ],                   // Indonesian (Yesus) => English, Indonesian
	    13169 => [ "en" ],                         // Thai => English
	    20601 => [ "en", "zh-Hans", "zh-Hant" ],   // Cantonese => English, Chinese Simplified, Chinese Traditional
	    1927  => [ "en", "id" ],                   // Malay => English, Indonesian
	    5871  => [ "en", "hi" ],                   // Tamil => English, Hindi
	    21028 => [ "es" ]
	];
	
	/**
	 * @var \Doctrine\DBAL\Connection
	 */
	private $conn;
	
	/** 
	 * @var \Arclight\MMDBBundle\php\ovp\OvpStorageServiceInterface
	 */
	private $ovpStorageService;
	
	/**
	 * @var string
	 */
	private $projectDir;
	
	/**
	 * @var ApiRequestBuilder
	 */
	private $api;
	
	/**
	 * @var YouTubeServiceBuilder
	 */
	private $yts;
	
	/**
	 * @var YouTubeRequestBuilder
	 */
	private $yt;
	
	/**
	 * @var ArclightShareQueryHelper
	 */
	private $shareQuery;
	
	/**
	 * @var array Key = wess lang ID, value = array containing keys:  name, locale
	 */
	private $youTubeLocales;
	
	/**
	 * @var array Key = media_asset ID, value = array containing keys:  shareUniqueId, mediaLocationShareId, mediaComponentId, languageId
	 */
	private $videosInChannel = [];
	
	/**
	 * @var array 
	 */
	private $languagesTitles = [];
	
	// Command options:
	private $uploadVideos = false;
	private $skipContains = false;
	private $uploadLocalizations = false;
	private $uploadCaptions = false;
	private $uploadPlaylist = false;
	private $reconcileVideos = false;
	private $downloadVideos = false;
	private $deleteCaptions = false;
	private $deleteInvalidCaptions = false;
	private $reconcileCaptions = false;
	private $includeParentInChildrenTitles = false;
	private $updateVideoTitles = false;
	private $updateVideoStatus = false;
	private $updateVideoLocation = false;
	private $playlistId = null;
	private $privacyStatus = null;
	private $publishAt = null;
	
	public function __construct(\Doctrine\DBAL\Connection $mmdbConnection, 
	                            \Arclight\MMDBBundle\php\ovp\OvpStorageServiceInterface $ovpStorageService,
								string $projectDir)
	{
	    parent::__construct();
	    
	    $this->conn = $mmdbConnection;
	    $this->ovpStorageService = $ovpStorageService;
	    $this->projectDir = $projectDir;
	}
	
    protected function configure()
    {
        $this		
	        ->setName('import:uploadProductToYouTube')
	        ->addArgument('useraccountemail', InputArgument::REQUIRED, 'The Google user account email address used for authentication (e.g. media@jesusfilm.org)')
	        ->addArgument('projectname[:channeltitle]', InputArgument::REQUIRED, 'The api project name and brand account channel title to be used for YouTube API access (e.g. "Conversation Starters Project:Conversation Starters")')
	        ->addArgument('mediaComponentId', InputArgument::REQUIRED, 'The media component ID of the product (parent+children) to be uploaded to the given channel')
	        ->addArgument('languageIds', InputArgument::IS_ARRAY, 'Media language IDs to be uploaded to then given channel')
	        ->addOption('uploadvideos', null, InputOption::VALUE_NONE, 'Upload parent+child videos to YouTube (downloading each first, if necessary)')
	        ->addOption('skipcontains', null, InputOption::VALUE_NONE, 'Skip any child videos')
	        ->addOption('includeparentinchildrentitles', null, InputOption::VALUE_NONE, 'Include parent name in child videos, if true')
	        ->addOption('uploadlocalizations', null, InputOption::VALUE_NONE, 'Upload localizations for videos already in channel that match the given media component+children and language IDs')
	        ->addOption('uploadcaptions', null, InputOption::VALUE_NONE, 'Upload captions for videos already in channel that match the given media component+children and language IDs')
	        ->addOption('uploadplaylist', null, InputOption::VALUE_NONE, 'Upload playlist+items to YouTube based on children of the given media component + language ID')
	        ->addOption('updatevideotitles', null, InputOption::VALUE_NONE, 'Update title(s) of the given video(s)')
	        ->addOption('languagestitlesfile', null, InputOption::VALUE_REQUIRED, 'Optional - if provided, provides the list of audio languages to be processed and overrides the Arclight titles for the given locale(s)')
	        ->addOption('updatevideostatus', null, InputOption::VALUE_NONE, 'Update status (privacy etc) of the given video(s)')
	        ->addOption('updatevideolocation', null, InputOption::VALUE_NONE, 'Update location of the given video(s)')
	        ->addOption('downloadvideos', null, InputOption::VALUE_NONE, 'Download videos to local system in preparation for eventual --uploadvideos')
	        ->addOption('listallvideos', null, InputOption::VALUE_NONE, 'List all videos in channel')
	        ->addOption('reconcilevideos', null, InputOption::VALUE_NONE, 'Reconcile videos already uploaded to YouTube but that did not make it into the DB')
	        ->addOption('deletecaptions', null, InputOption::VALUE_NONE, 'Delete captions for videos already in channel that match the given media component+children and language ID')
	        ->addOption('deleteinvalidcaptions', null, InputOption::VALUE_NONE, 'One off')
	        ->addOption('reconcilecaptions', null, InputOption::VALUE_NONE, 'One off')
	        ->addOption('uploadallthumbnails', null, InputOption::VALUE_NONE, 'Upload thumbnails for ALL videos current in channel (ignores media component and language ID arguments)')
	        ->addOption('reportservicecallcounts', null, InputOption::VALUE_NONE, 'Utility option - report on current service call counts.  Only utility actions are performed; no uploading.')
	        ->addOption('forcerefreshaccesstoken', null, InputOption::VALUE_NONE, '')
	        ->addOption('dumpyoutubelocales', null, InputOption::VALUE_NONE, 'Utility option - look in the DB for supported YouTube locales.  Only utility actions are performed; no uploading.')
	        ->addOption('sampleanalyticsquery', null, InputOption::VALUE_NONE, 'Utility option - run a sample google analytics query')
	        ->addOption('listplaylistitems', null, InputOption::VALUE_OPTIONAL, 'Optional - specific playlist ID of which to list items')
	        ->addOption('listvideos', null, InputOption::VALUE_NONE, 'List video in channel using media component ID and language IDs arguments')
	        ->addOption('listvideosbyyoutubeid', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'List videos in channel by YouTube id')
	        ->addOption('videolanguageoverride', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Optional - specific Youtube video language code to use instead of the bcp47 code associated with the language in Arclight.  If providing multiple values, each should both the language ID and the video language code separated by a colon, e.g.  529:en')
	        ->addOption('videocountryoverride', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Optional - country code to use instead of the primaryCountryId for each language in Arclight.  If providing multiple values, each should both the language ID and the video language code separated by a colon, e.g.  529:US')
	        ->addOption('parentid', null, InputOption::VALUE_REQUIRED, 'Optional - since titles of segments are built using parent title, this specifies which parent to use')
	        ->addOption('playlistid', null, InputOption::VALUE_REQUIRED, 'Optional - if provided, can be used to add a playlist URL to an uploaded videos long description')
	        ->addOption('privacystatus', null, InputOption::VALUE_REQUIRED, 'Optional - if provided, will be used during uploadvideo / updatingvideostatus', null)
	        ->addOption('publishatutc', null, InputOption::VALUE_REQUIRED, 'Optional - if provided, will be used during uploadvideo / updatingvideostatus, and privacy status will be set to "private".  Expects UTC datetime in format:  "Y-m-d H:i:s"', null)
	        ->addOption('publishathoursfromnow', null, InputOption::VALUE_REQUIRED, 'Optional - if provided, will be used during uploadvideo / updatingvideostatus, and privacy status will be set to "private"', null)
            ->addOption('test', 't', InputOption::VALUE_NONE, 'Outputs command arguments')
	        ->setDescription('Uploads product videos to YouTube, and creates playlist');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // $output->writeln("Check and deal with lines 1450 - 1455!!");
        // return;
        
   		$userAccountEmail = $input->getArgument('useraccountemail');
   		$projectNameChannelTitle = $input->getArgument('projectname[:channeltitle]');
   		$parts = explode(':', $projectNameChannelTitle);
   		$projectName = $parts[0];
   		$channelTitle = (count($parts) > 1) ? $parts[1] : $projectName;
   		
   		$mediaComponentId = $input->getArgument('mediaComponentId');
   		$languageIds = $input->getArgument('languageIds'); // array

		$languagesTitlesFile = $input->hasOption('languagestitlesfile') ? $input->getOption('languagestitlesfile') : null;
		if (null !== $languagesTitlesFile) {

			$this->extractLanguagesTitles($languagesTitlesFile);
			$languageIds = array_map('strval', array_keys($this->languagesTitles));

			if (Utils::isEmpty($languageIds)) {
				throw new \Exception("No [languageid] column values found in file [".$input->getOption('languagestitlesfile')."]");
			}
		}

		$isTest = $input->getOption('test');

		$output->writeln("");
		$output->writeln("Incoming arguments:");
   		$output->writeln("\t[ARGUMENT] User account email:  ".$userAccountEmail);
   		$output->writeln("\t[ARGUMENT] Project name:  ".$projectName);
   		$output->writeln("\t[ARGUMENT] Channel title:  ".$channelTitle);
   		$output->writeln("\t[ARGUMENT] Media component ID:  ".$mediaComponentId);
   		$output->writeln("\t[ARGUMENT] Language IDs:  ".implode(", ", $languageIds));
		$output->writeln("");

		$this->parsePrivacyStatus($input, $output);

		if (!Utils::isEmpty($this->languagesTitles)) {
			$this->outputLanguageTitleOverrides($output);
		}
		$output->writeln("");
		if (true === $isTest) {
			return Parameters::COMMAND_SUCCESS;
		}
   		
   		$client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URI]);
   		$this->api = new ApiRequestBuilder($client, self::DEFAULT_ARCLIGHT_API_KEY, $output);
   		$this->yts = new YouTubeServiceBuilder($userAccountEmail, $projectName, $channelTitle, $this->conn, $output);
   		
   		$userId = $this->yts->getUserId();
   		$channelId = $this->yts->getChannelId();
   		
   		$this->shareQuery = new ArclightShareQueryHelper(
   				ArclightShareQueryHelper::YOUTUBE_SHARE_NAME, 
   				$channelId,
   				$userId,
   				$this->conn, 
   				$output);
	   		
   		$this->yt = new YouTubeRequestBuilder($this->yts, $this->shareQuery, $this->ovpStorageService, $output);

		//var_dump($this->yt->listI18NRegions());
		//var_dump($this->yt->listI18NLanguages());
		//var_dump($this->yt->listI18NLanguages("ar"));
		//exit;
	   			   		
		$this->youTubeLocales = $this->shareQuery->lookupLocales();
		//TODO:  total hack until bcp47 code is fixed in the database:
		if (isset($this->youTubeLocales["21028"]) && $this->youTubeLocales["21028"]["locale"] === "es") {
			$this->youTubeLocales["21028"]["locale"] = "es-419";
		}
   		
   		$dumpYouTubeLocales = $input->hasOption('dumpyoutubelocales') ? $input->getOption('dumpyoutubelocales') : false;
   		if (true === $dumpYouTubeLocales) {
   			$output->writeln(json_encode($this->youTubeLocales, JSON_PRETTY_PRINT));
   			$output->writeln("Count: ".count($this->youTubeLocales));
   			return;
   		}
   		
   		$reportServiceCallCounts= $input->hasOption('reportservicecallcounts') ? $input->getOption('reportservicecallcounts') : false;
   		if (true === $reportServiceCallCounts) {
   			$this->reportServiceCallCounts($output);
   			return;
   		}
   		
   		$this->uploadVideos = $input->hasOption('uploadvideos') ? $input->getOption('uploadvideos') : false;
   		$this->skipContains = $input->hasOption('skipcontains') ? $input->getOption('skipcontains') : false;
   		$this->uploadLocalizations = $input->hasOption('uploadlocalizations') ? $input->getOption('uploadlocalizations') : false;
   		$this->uploadCaptions = $input->hasOption('uploadcaptions') ? $input->getOption('uploadcaptions') : false;
   		$this->uploadPlaylist = $input->hasOption('uploadplaylist') ? $input->getOption('uploadplaylist') : false;
   		$this->updateVideoTitles = $input->hasOption('updatevideotitles') ? $input->getOption('updatevideotitles') : false;
   		$this->updateVideoStatus = $input->hasOption('updatevideostatus') ? $input->getOption('updatevideostatus') : false;
   		$this->updateVideoLocation = $input->hasOption('updatevideolocation') ? $input->getOption('updatevideolocation') : false;
   		$this->downloadVideos = $input->hasOption('downloadvideos') ? $input->getOption('downloadvideos') : false;
   		$this->reconcileVideos = $input->hasOption('reconcilevideos') ? $input->getOption('reconcilevideos') : false;
   		$this->deleteCaptions = $input->hasOption('deletecaptions') ? $input->getOption('deletecaptions') : false;
   		$this->deleteInvalidCaptions = $input->hasOption('deleteinvalidcaptions') ? $input->getOption('deleteinvalidcaptions') : false;
   		$this->reconcileCaptions = $input->hasOption('reconcilecaptions') ? $input->getOption('reconcilecaptions') : false;
   		$this->includeParentInChildrenTitles = $input->hasOption('includeparentinchildrentitles') ? $input->getOption('includeparentinchildrentitles') : false;
   		$uploadAllThumbnails = $input->hasOption('uploadallthumbnails') ? $input->getOption('uploadallthumbnails') : false;
   		$forceRefreshAccessToken = $input->hasOption('forcerefreshaccesstoken') ? $input->getOption('forcerefreshaccesstoken') : false;
   		$sampleAnalyticsQuery = $input->hasOption('sampleanalyticsquery') ? $input->getOption('sampleanalyticsquery') : false;
   		$listAllVideos = $input->hasOption('listallvideos') ? $input->getOption('listallvideos') : false;
   		$listPlaylistItems = $input->getOption('listplaylistitems');
   		$listVideos = $input->hasOption('listvideos') ? $input->getOption('listvideos') : false;
   		$listVideosByYoutubeId = array_filter($input->getOption('listvideosbyyoutubeid'));
   		$vlos = array_filter($input->getOption('videolanguageoverride'));
   		$vcos = array_filter($input->getOption('videocountryoverride'));
   		$parentId = $input->hasOption('parentid') ? $input->getOption('parentid') : null;
   		$this->playlistId = $input->hasOption('playlistid') ? $input->getOption('playlistid') : null;
   		
   		$videoLanguageOverrides = $this->parseLanguageSpecificOverides($vlos, $languageIds, false);
   		$videoCountryOverrides = $this->parseLanguageSpecificOverides($vcos, $languageIds, true);
   		
   		$this->videosInChannel = $this->shareQuery->getVideosOnChannelPageFromDB();
   		
   		if (true === $listAllVideos) {
   			$this->yt->searchListAllMyVideos($this->videosInChannel, $output);
   			exit;
   			
   		}
	   		
   		if (true === $listVideos) {
   		    $filteredVideosInChannel = $this->filterVideosInChannelByMCLs($mediaComponentId, $languageIds);
	        $this->yt->listVideosByYoutubeId($filteredVideosInChannel, $output);
	        exit;
   		}
   		
   		if (!Utils::isEmpty($listVideosByYoutubeId)) {
   		    $filteredVideosInChannel = $this->filterVideosInChannelByYoutubeIds($listVideosByYoutubeId);
   		    if (Utils::isEmpty($filteredVideosInChannel)) {
   		        $filteredVideosInChannel = [];
   		        foreach ($listVideosByYoutubeId as $ytid) {
   		            $filteredVideosInChannel[] = [ 'shareUniqueId' => $ytid ];
   		        }
   		    }
   		    $this->yt->listVideosByYoutubeId($filteredVideosInChannel, $output);
   		    exit;
   		}
   		
   		if (!Utils::isEmpty($listPlaylistItems)) {
   		    
   		    $output->writeln("Items contained within playlist: ".$listPlaylistItems);
   		    
   		    $playlistItems = $this->yt->getItemsInPlaylistYT($listPlaylistItems);
   		    
   		    if (!Utils::isEmpty($playlistItems)) {
   		        foreach ($playlistItems as $i) {
   		            $output->writeln("ID: ".$i['id'].
   		                             ", video ID: ".$i['videoId'].
   		                             ", position: ".$i['position'].
   		                             ", title: ".$i['title']);
   		        }
   		    }
   		    
   		    $output->writeln("Playlist item count: ".count($playlistItems ?? []));
   		    
   		    exit;
   		}
   		
   		if (true === $sampleAnalyticsQuery) {
   			//$this->createYouTubeReportJobs();
   			$this->downloadYouTubeReports(new \DateTime("-5 day", new \DateTimeZone('UTC')));
   			return;
   		}
   		
   		$parentMetadata = $this->api->mediaComponent($parentId ?? $mediaComponentId, 'languageIds');
   		if (!isset($parentMetadata['mediaComponentId'])) {
   			$output->writeln("Media component [$mediaComponentId] not found.  Exiting...");
   			return;
   		} 
   		
   		$containsIds = null;
   		if (false === $this->skipContains) {
   			$containsIds = $this->api->mediaComponentContains($parentId ?? $mediaComponentId);
   		}
   		
   		if (is_null($containsIds) && true === $this->includeParentInChildrenTitles) {
   		    throw new \Exception("Invalid option 'includeparentinchildrentitles' since media component ID "
   		        ."[$mediaComponentId] has no children.  Either include a 'parentid' option, or remove "
   		        ."'includeparentinchildrentitles'.");
   		}
   		
   		// TODO:  what if playlist already exists and has some items in it?
   		
   		foreach($languageIds as $lang) {
   			
   			if ($lang === '184854' && !array_key_exists($lang, $this->youTubeLocales)) {
				$this->youTubeLocales[$lang] = [ 'name' => 'Burmese, Standard', 'locale' => 'my' ];
   			}
   			
   			if ($lang === '184855' && !array_key_exists($lang, $this->youTubeLocales)) {
				$this->youTubeLocales[$lang] = [ 'name' => 'Burmese, Common', 'locale' => 'my' ];
   			}
   			
   			if (!array_key_exists($lang, $this->youTubeLocales)) {
   				$output->writeln("Language ID [$lang] not supported for YouTube.  Processing of media asset skipped...");
   				continue;
   			} 

   			$languageMetadata = $this->api->mediaLanguage($lang);
   			$languageMetadata['youtube_locale'] = $videoLanguageOverrides[$lang] ?? $this->youTubeLocales[$lang]['locale'];
   			$languageName = $languageMetadata['name'];
   			
   			if ($lang === '184854') {
				$languageMetadata['bcp47'] = 'my';
   			}
   			
   			if ($lang === '184855') {
				$languageMetadata['bcp47'] = 'my';
   			}
   			
   			if ($lang === '584') {
				$languageMetadata['bcp47'] = 'pt-BR';
   			}
   			
   			if ($lang === '20615') {
				$languageMetadata['bcp47'] = 'zh-Hans';
   			}
   			
   			if ($lang === '21028') {
				$languageMetadata['bcp47'] = 'es-419';
   			}
   			
   			$output->writeln("Youtube locale: ".$languageMetadata['youtube_locale']);
   			$output->writeln("Bcp47: ".$languageMetadata['bcp47']);
   			
   			$locationCountryId = $videoCountryOverrides[$lang] ?? $languageMetadata['primaryCountryId'] ?? null;
   			$countryMetadata = null;
   			
   			if (!Utils::isEmpty($locationCountryId)) {
   			    $countryMetadata = $this->api->mediaCountry($locationCountryId);
   			}
		    
		    $languageMetadata['locationLongitude'] = $countryMetadata['longitude'] ?? null;
		    $languageMetadata['locationLatitude'] = $countryMetadata['latitude'] ?? null;
		    $languageMetadata['locationDescription'] = $countryMetadata['name'] ?? null;
		    
   			if (true === $this->downloadVideos || true === $this->uploadVideos || true === $this->uploadLocalizations || 
   				true === $this->uploadCaptions || true === $this->deleteCaptions || true === $this->deleteInvalidCaptions || 
   				true === $this->reconcileCaptions || true === $this->updateVideoTitles || true === $this->updateVideoStatus ||
   			    true === $this->updateVideoLocation) {
   					
   				if (false === $this->shouldSkipProcessVideo($parentMetadata['mediaComponentId'], $lang)) {
   				    if ($mediaComponentId === $parentMetadata['mediaComponentId']) {
	   				   if (in_array($lang, $parentMetadata['languageIds'])) {
	   					  $this->processMediaAsset($parentMetadata, $languageMetadata, $output);
	   					    sleep(30);
	   				   } else {
	   					  $output->writeln("WARNING: parent video [$mediaComponentId] not available in language [$languageName].  Skipping...");
	   				   }
   				    }
   				} 
   				
   				$containsPosition = 1;
   				if (!is_null($containsIds)) {
	   				foreach($containsIds as $containsId) {
	   					if (false === $this->shouldSkipProcessVideo($containsId, $lang)) {
	   					    if (null === $parentId || $mediaComponentId === $containsId) {
		   						$containsMetadata = $this->api->mediaComponent($containsId, 'languageIds');
		   						$containsMetadata['containsPosition'] = $containsPosition;
		   						$containsMetadata['containsCount'] = count($containsIds);
			   					if (in_array($lang, $containsMetadata['languageIds'])) {
			   						$this->processMediaAsset($containsMetadata, $languageMetadata, $output, $parentMetadata);
			   						sleep(30);
			   					} else {
			   						$output->writeln("WARNING: contained video [$containsId] not available in language [$languageName].  Skipping...");
			   					}
	   					    }
	   					}
	   					$containsPosition++;
	   				}
   				}
   			}
   			
   			if (true === $this->uploadPlaylist) {
   				$this->uploadToPlaylist($parentMetadata, $languageMetadata, $output);
   			}
   		}
   		
   		if (true === $uploadAllThumbnails) {
   			$this->videosInChannel = $this->shareQuery->getVideosOnChannelPageFromDB();
   			foreach ($this->videosInChannel as $vid) {
   				$shareUniqueId = $vid['shareUniqueId'];
   				$mediaComponentId = $vid['mediaComponentId'];
   				
   				$output->writeln("Updating thumbnail for YouTube video ID [$shareUniqueId] and media component ID [$mediaComponentId]");
   				
   				$metadata = $this->api->mediaComponent($mediaComponentId);
   				$imageUrl = ApiRequestBuilder::chooseThumbnailUrl($metadata);
   				
   				$this->yt->uploadVideoThumbnail($shareUniqueId, $imageUrl);
   			}
   		}
   		
   		if (true === $this->reconcileVideos) {
   			$this->videosInChannel = $this->shareQuery->getVideosOnChannelPageFromDB();
   			$videosInChannelYT = $this->yt->getVideosInChannelYT($channelId, $output);
   			
   			if (count($this->videosInChannel) != count($videosInChannelYT)) {
   				$output->writeln("Mismatch number of videos between DB [".count($this->videosInChannel)."] and YT [".count($videosInChannelYT)."]");
   				
   				$videoIdsInChannel = array_map(function($a) { return $a['shareUniqueId']; }, $this->videosInChannel);
   				$missing = array_filter($videosInChannelYT, function($a) use ($videoIdsInChannel) { return !in_array($a['id'], $videoIdsInChannel); });
   				
   				foreach ($missing as $missingVideo) {
   					
   					$shareUniqueId = $missingVideo['id'];
   					$title = $missingVideo['title'];
   					$permalinkUrl = "https://youtu.be/$shareUniqueId";
   					
   					$mcId = $this->findMediaComponentByTitle($title, $parentMetadata, $containsIds);
   					if (is_null($mcId)) {
   						$output->writeln("Unable to reconcile title [$title] to a media component ID");
   					} else {
   						$mediaAsset = $this->api->mediaAsset($mcId, $languageIds[0]);
   						$mediaAsset['mediaAssetId'] = $this->shareQuery->lookupMediaAssetId($mediaAsset['refId']);
   						
   						$mediaLocationShareId = $this->shareQuery->insertMediaLocationSocial($shareUniqueId, $mediaAsset, $permalinkUrl);
   						
   						$output->writeln("Adding missing video [".$mediaAsset['refId']."] to DB as mediaLocationShareId [$mediaLocationShareId]");
   					}
   				}
   			} else {
   				$output->writeln("No videos to reconcile for channel [$channelId] - all YT videos are already in the DB.");
   			}
		}
		   
		return Parameters::COMMAND_SUCCESS;
    }

	protected function parsePrivacyStatus(InputInterface $input, OutputInterface $output)
	{		
		$privacyStatusDefault = 'public';
		$publishAtUtc = $input->hasOption('publishatutc') ? $input->getOption('publishatutc') : null;
		$publishAtHoursFromNow = $input->hasOption('publishathoursfromnow') ? $input->getOption('publishathoursfromnow') : null;

		if (null !== $publishAtUtc) {

			$this->publishAt = \DateTime::createFromFormat("Y-m-d H:i:s", $publishAtUtc, new \DateTimeZone('UTC'));
			$privacyStatusDefault = 'private';

			if (false === $this->publishAt) {
				throw new \Exception("Unable to create DateTime from [publishatutc] option value:  ".$publishAtUtc);
			}
			$output->writeln("\t[OPTION] publishatutc:  ".$this->publishAt->format(\DateTime::ATOM));

		} else if (null !== $publishAtHoursFromNow) {

			$this->publishAt = new \DateTime("$publishAtHoursFromNow hour");
			$privacyStatusDefault = 'private';

			if (false === $this->publishAt) {
				throw new \Exception("Unable to create DateTime from [publishatutc] option value:  ".$publishAtUtc);
			}
			$output->writeln("\t[OPTION] publishathoursfromnow ($publishAtHoursFromNow):  ".$this->publishAt->format(\DateTime::ATOM));

		}

		$this->privacyStatus = $input->getOption('privacystatus');
		if (null === $this->privacyStatus) {
			$this->privacyStatus = $privacyStatusDefault;
		}

		$output->writeln("\t[OPTION] privacystatus:  ".$this->privacyStatus);

		if (null !== $this->publishAt && $this->privacyStatus !== 'private') {
			throw new \Exception("Setting 'publishAt' date without having privacy status set to 'private' is unsupported by Youtube API");
		}
	}

	protected function outputLanguageTitleOverrides(OutputInterface $output)
	{
        $maxLengths = [
            'language_id' =>  7, 
            'bcp47' => 12
        ];

		$output->writeln("\t[OPTION] Language - title overrides:");
		foreach ($this->languagesTitles as $languageId => $languageTitles) {
            foreach ($languageTitles as $bcp47 => $languageInfo) {
				
                $lidPad = max($maxLengths['language_id'] - mb_strlen($languageId, 'UTF-8'), 0);
                $bcpPad = max($maxLengths['bcp47'] - mb_strlen($bcp47, 'UTF-8'), 0);

                $output->write("\t\tLang ID:  {$languageId} ".str_repeat(' ', $lidPad));
                if (true === $languageInfo['isnativelocale']) {
                    $output->write("bcp47 (native locale):  {$bcp47} ".str_repeat(' ', $bcpPad));
                } else {
					$output->write("bcp47:                  {$bcp47} ".str_repeat(' ', $bcpPad));
				}

                if (!Utils::isEmpty($languageInfo['title'])) {
                    if (true === $languageInfo['istitleyoutubeready']) {
                        $output->write(' title (youtube ready):  '.$languageInfo['title']);
                    } else {
						$output->write(' title:                  '.$languageInfo['title']);
					}
				}

                if (!Utils::isEmpty($languageInfo['description'])) {
                    if (true === $languageInfo['idescriptionyoutubeready']) {
                        $output->write(' description (youtube ready):  '.$languageInfo['description']);
                    } else {
						$output->write(' description:                  '.$languageInfo['description']);
					}
				}

				$output->writeln("");
            }
		}
	}

	/**
	 * Assumes that the file is pipe-delimited, and contains the following
	 * column headers:
	 * 	languageid
	 *  bcp47
	 *  title
	 *  description
	 *  languagename
	 *  tags (comma delmited)
	 *  isnativelocale (default true:  true if bcp47 is considered 'native' locale for audio language)
	 *  istitleyoutubeready (default true:  if false, construct youtube title using algorithm + parts)
	 *  isdescriptionyoutubeready (default true)
	 * 
	 * For each unique languageid, there may be multiple bcp47+title
	 * combinations, but only one associated bcp47+title should have 
	 * isnativelocale = true.  
	 */
	protected function extractLanguagesTitles(string $videoTitlesFile): void
	{
		$finder = new Finder();
		$finder->files()->in($this->projectDir)->name($videoTitlesFile);

        if (0 == count($finder)) {
            throw new \Exception("File not having name [$videoTitlesFile] not found.");
        }

        foreach ($finder as $file) {
            echo "\n\tReading contents of data file:  ".$file->getRealpath().PHP_EOL;

            $sourceHandle = fopen($file->getRealpath(), 'r');

            $rowIndex = 0;
            $colIndexesToFieldNames = [];

            while (($row = fgetcsv($sourceHandle, 0, '|')) !== false) {
                if (0 == $rowIndex) {
                    $colIndexesToFieldNames = $row;

                    ++$rowIndex;

                    continue;
                }

				$currentRow = $this->mapColumnIndexesToNames($row, $colIndexesToFieldNames);
				$this->languagesTitles[$currentRow['languageid']][$currentRow['bcp47']] = [ 
					'title' => $currentRow['title'],
					'description' => $currentRow['description'] ?? "",
					'languagename' => $currentRow['languagename'] ?? "",
					'languagenameen' => $currentRow['languagenameen'] ?? "",
					'tags' => explode(',', $currentRow['tags'] ?? ""),
					'isnativelocale' => filter_var($currentRow['isnativelocale'] ?? 1, FILTER_VALIDATE_BOOLEAN),
					'istitleyoutubeready' => filter_var($currentRow['istitleyoutubeready'] ?? 1, FILTER_VALIDATE_BOOLEAN),
					'isdescriptionyoutubeready' => filter_var($currentRow['isdescriptionyoutubeready'] ?? 1, FILTER_VALIDATE_BOOLEAN),
				];
            }
        }
	}
    
    protected function mapColumnIndexesToNames(array $row, array $colIndexesToFieldNames) {
    	
	    	$mappedRow = [];
	    	
	    	foreach ($row as $index => $value) {
	    		$fieldName = $colIndexesToFieldNames[$index];
	    		$mappedRow[$fieldName] = $value;
	    	}
	    	
	    	return $mappedRow;
	    	
    }
    
    protected function parseLanguageSpecificOverides(array $incomingOverrides, array $languageIds, bool $allowEmptyCode) {
        
        $parsedOverrides = [];
        if (is_array($incomingOverrides)) {
            foreach ($incomingOverrides as $ilo) {
                $matches = null;
                $codeMatch = (true === $allowEmptyCode) ? "([\w\-]+)?" : "([\w\-]+)";
                if (preg_match('/(\d+):'.$codeMatch.'/', $ilo, $matches)) {
                    $parsedOverrides[$matches[1]] = $matches[2] ?? "";
                } else if (1 === count($languageIds) && 1 === count($incomingOverrides)) {
                    $parsedOverrides[$languageIds[0]] = $ilo;
                } else {
                    throw new \Exception("Language override option [$ilo] does not match langid:code format");
                }
            }
            foreach ($parsedOverrides as $langId => $ilo) {
                if (!in_array($langId, $languageIds)) {
                    throw new \Exception("Language override option [$ilo] has language [$langId], which doesn't match any of the 'languageids' argument(s)");
                }
            }
        }
        
        return $parsedOverrides;
    }
    
    protected function createYouTubeReportJobs()
    {
    		$youTubeService = $this->yts->getAuthorizedYouTubeQueryService();
    	
    		$youtubeReporting= new \Google_Service_YouTubeReporting($youTubeService->getClient());
    	
		//$reportTypes = $youtubeReporting->reportTypes->listReportTypes();
		//var_dump($reportTypes);
		//return;
		
		// JESUS Film:
		//		channel_basic_a2, "User activity" - job id: 23a2954e-a4c9-4609-b700-377760d87887
		// 		channel_combined_a2, "Combined" - job id: b7803d82-b6d0-407b-88b4-30be5e9e1f73
		//
		// Conversation Starters:
		//		channel_basic_a2, "User activity" - job id: 0e65a5ed-b032-4bee-98f9-7a0ff6f298e0
		// 		channel_combined_a2, "Combined" - job id: 0564d3b8-1248-4153-a82d-fbb5f05dc11c
		//
		$reportingJob1 = new \Google_Service_YouTubeReporting_Job();
		$reportingJob1->setReportTypeId("channel_basic_a2");
		$reportingJob1->setName("User activity");
		
		// Call the YouTube Reporting API's jobs.create method to create a job.
		$jobCreateResponse = $youtubeReporting->jobs->create($reportingJob1);
		
		var_dump($jobCreateResponse);
		
		$reportingJob2 = new \Google_Service_YouTubeReporting_Job();
		$reportingJob2->setReportTypeId("channel_combined_a2");
		$reportingJob2->setName("Combined");
		
		// Call the YouTube Reporting API's jobs.create method to create a job.
		$jobCreateResponse = $youtubeReporting->jobs->create($reportingJob2);
		
		var_dump($jobCreateResponse);
    }
    
    protected function downloadYouTubeReports(\DateTime $createdAfterDt)
    {
    		$createdAfter = $createdAfterDt->setTime(0, 0)->format("Y-m-d\TH:i:s\Z");
    		
    		$youTubeService = $this->yts->getAuthorizedYouTubeReportService();
    		
    		$youtubeReporting= new \Google_Service_YouTubeReporting($youTubeService->getClient());
    		
    		$reportingJobs = $youtubeReporting->jobs->listJobs();
    		
    		if (0 == count($reportingJobs)) {
    			echo "\t No reporting jobs found in list".PHP_EOL;
    		}
    		
    		foreach ($reportingJobs as $job) {
    	    		$reports = $youtubeReporting->jobs_reports->listJobsReports($job['id'], [ 'createdAfter' => $createdAfter ]);
    			foreach ($reports as $report) {
    				
    				$startDt = \DateTime::createFromFormat("Y-m-d\TH:i:s\Z", $report['startTime'], new \DateTimeZone('UTC'));
    				$endDt = \DateTime::createFromFormat("Y-m-d\TH:i:s\Z", $report['endTime'], new \DateTimeZone('UTC'));
    				$createDt = \DateTime::createFromFormat("Y-m-d\TH:i:s.u\Z", $report['createTime'], new \DateTimeZone('UTC'));
    				$reportDownloadUrl = $report['downloadUrl'];
    				
    				// DOWNLOAD REPORT:
    				$client = $youtubeReporting->getClient();
    				// Setting the defer flag to true tells the client to return a request which can be called
    				// with ->execute(); instead of making the API call immediately.
    				$client->setDefer(true);

    				// Call YouTube Reporting API's media.download method to download a report.
    				$request = $youtubeReporting->media->download('', array('alt' => 'media'));
    				$request = $request->withUri(new \GuzzleHttp\Psr7\Uri($reportDownloadUrl));
    				$responseBody = '';
    				try {
    					$response = $client->execute($request);
    					$responseBody = $response->getBody();
    				} catch (Google_Service_Exception $e) {
    					$responseBody = $e->getTrace()[0]['args'][0]->getResponseBody();
    				}
    				$fileName = $job['reportTypeId']."_".$startDt->format('Y-m-d').".txt";
    				file_put_contents($fileName, $responseBody);
    				$client->setDefer(false);
    				
    				echo "\t Retrieved report for filename [$fileName]".PHP_EOL;
    			}
    		}
    }
    
    protected function reportServiceCallCounts(OutputInterface $output)
    {
	    	$overallCallCountOver24Hours = $this->shareQuery->getServiceCallCountSince(1440, null, true);
	    	$overallQuotaCostOver24Hours = $this->shareQuery->getServiceQuotaCostSince(1440, null, true);
	    	
	    	$uploadVideoCallCountOver24Hours = $this->shareQuery->getServiceCallCountSince(1440, YouTubeRequestBuilder::CALL_ID_UPLOAD_VIDEO, true);
	    	$uploadVideoCallCountOver12Hours = $this->shareQuery->getServiceCallCountSince(720, YouTubeRequestBuilder::CALL_ID_UPLOAD_VIDEO, true);
	    	$uploadVideoCallCountOver6Hours = $this->shareQuery->getServiceCallCountSince(360, YouTubeRequestBuilder::CALL_ID_UPLOAD_VIDEO, true);
	    	$uploadVideoCallCountOver3Hours = $this->shareQuery->getServiceCallCountSince(180, YouTubeRequestBuilder::CALL_ID_UPLOAD_VIDEO, true);
	    	
	    	$uploadVideoQuotaCostOver24Hours = $this->shareQuery->getServiceQuotaCostSince(1440, YouTubeRequestBuilder::CALL_ID_UPLOAD_VIDEO, true);
	    	$uploadVideoQuotaCostOver12Hours = $this->shareQuery->getServiceQuotaCostSince(720, YouTubeRequestBuilder::CALL_ID_UPLOAD_VIDEO, true);
	    	$uploadVideoQuotaCostOver6Hours = $this->shareQuery->getServiceQuotaCostSince(360, YouTubeRequestBuilder::CALL_ID_UPLOAD_VIDEO, true);
	    	$uploadVideoQuotaCostOver3Hours = $this->shareQuery->getServiceQuotaCostSince(180, YouTubeRequestBuilder::CALL_ID_UPLOAD_VIDEO, true);
	    	
	    	$uploadCaptionCallCountOver24Hours = $this->shareQuery->getServiceCallCountSince(1440, YouTubeRequestBuilder::CALL_ID_UPLOAD_CAPTION, true);
	    	$uploadCaptionQuotaCostOver24Hours = $this->shareQuery->getServiceQuotaCostSince(1440, YouTubeRequestBuilder::CALL_ID_UPLOAD_CAPTION, true);
	    	
	    	$output->writeln("Since last 24 hours:  [".number_format($overallCallCountOver24Hours).
	    			"] service calls, [".number_format($overallQuotaCostOver24Hours).
	    			"] quota cost, [".number_format($uploadVideoCallCountOver24Hours).
	    			"] upload calls, [".number_format($uploadVideoQuotaCostOver24Hours).
	    			"] upload quota cost, [".number_format($uploadCaptionCallCountOver24Hours).
	    			"] caption upload calls, [".number_format($uploadCaptionQuotaCostOver24Hours).
	    			"] caption upload quota cost");
	    	$output->writeln("Since last 12 hours:  [".number_format($uploadVideoCallCountOver12Hours).
	    			"] upload calls, [".number_format($uploadVideoQuotaCostOver12Hours)."] upload quota cost");
	    	$output->writeln("Since last 6 hours:   [".number_format($uploadVideoCallCountOver6Hours).
	    			"] upload calls, [".number_format($uploadVideoQuotaCostOver6Hours)."] upload quota cost");
	    	$output->writeln("Since last 3 hours:   [".number_format($uploadVideoCallCountOver3Hours).
	    			"] upload calls, [".number_format($uploadVideoQuotaCostOver3Hours)."] upload quota cost");
    	
    }
    
    protected function filterVideosInChannelByMCLs(string $mediaComponentId, array $languageIds) {
        
        $videosInChannel = array_filter($this->videosInChannel, function($a) use ($mediaComponentId, $languageIds) {
            return ($a['mediaComponentId'] == $mediaComponentId && in_array($a['languageId'], $languageIds));
        });
        
        return $videosInChannel;
        
    }
    
    protected function filterVideosInChannelByYoutubeIds(array $youtubeIds) {
        
        $videosInChannel = array_filter($this->videosInChannel, function($a) use ($youtubeIds) {
            return (in_array($a['shareUniqueId'], $youtubeIds));
        });
            
        return $videosInChannel;
            
    }
    
    protected function shouldSkipProcessVideo($mediaComponentId, $languageId)
    {
	    	if (false === $this->downloadVideos && true === $this->uploadVideos && false === $this->uploadCaptions && 
	    		false === $this->deleteCaptions && false === $this->deleteInvalidCaptions && false === $this->reconcileCaptions) {
    			
	    		$found = array_filter($this->videosInChannel, function($a) use ($mediaComponentId, $languageId) { 
	    			return ($a['mediaComponentId'] == $mediaComponentId && $a['languageId'] == $languageId); 
	    		});
	    		return count($found) > 0;
	    	}
	    	
	    	return false;
    }
    
    protected function findMediaComponentByTitle($title, $parentMetadata, $containsIds) 
    {
    		if ($title == $parentMetadata['title']) {
    			return $parentMetadata['mediaComponentId'];
    		}
    		
    		foreach ($containsIds as $containsId) {
    			$containsMetadata = $this->api->mediaComponent($containsId);
    			
    			if ($title == $containsMetadata['title']) {
    				return $containsMetadata['mediaComponentId'];
    			}
    		}
    		
    		return null;
    }
    
    protected function processMediaAsset($metadata, $languageMetadata, OutputInterface $output, $parentMetadata = null)
    {
    	if (false === $this->downloadVideos && false === $this->uploadVideos && false === $this->uploadLocalizations && 
    		false === $this->deleteCaptions && false === $this->deleteInvalidCaptions && false === $this->reconcileCaptions && 
    	    false === $this->uploadCaptions && false === $this->updateVideoTitles && false === $this->updateVideoStatus &&
    	    false === $this->updateVideoLocation) {
    			return;
    		}
    		
    		if ($metadata['contentType'] != 'video') {
    			return;
    		}
    		
//    		$output->writeln("Looking up media asset by [".$metadata['mediaComponentId']."] and [".$languageMetadata['languageId']."]");
    		
	    	$mediaAsset = $this->api->mediaAsset($metadata['mediaComponentId'], $languageMetadata['languageId']);
	    	
	    	$mediaAssetDb = $this->shareQuery->lookupMediaAssetDb($mediaAsset['refId']);
	    	
	    	$mediaAsset['mediaAssetId'] = $mediaAssetDb['id'];
	    	$mediaAsset['masterAssetUrl'] = $mediaAssetDb['master_asset_url'];
	    	
	    	if (empty($mediaAsset['mediaAssetId'])) {
	    		$output->writeln("Unable to lookup media asset ID.  Skipping....");
	    		return;
	    	} 
	    	
	    	$this->processVideo($metadata, $languageMetadata, $mediaAsset, $output, $parentMetadata);
	    	$this->processVideoCaptions($mediaAsset, $parentMetadata, $output);
	    	
    }
    
    protected function processVideo($metadata, $languageMetadata, $mediaAsset, OutputInterface $output, $parentMetadata = null) 
    {
	    	if (true === $this->downloadVideos) {
	    		if (!array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    			
	    			$basename = $mediaAsset['refId'];
	    			
	    			if (!Utils::isEmpty($mediaAsset['masterAssetUrl'])) {
	    			    
	    			    $output->writeln("Downloading video [".$metadata['mediaComponentId']."] in language [".$languageMetadata['name']."] from master assset URL");
	    			    
	    			    $this->yt->downloadUrlTmp($mediaAsset['masterAssetUrl'], $basename, true);
	    			    
	    			} else {
	    			    
	    			    $output->writeln("Downloading video [".$metadata['mediaComponentId']."] in language [".$languageMetadata['name']."] from transcoded streaming URL");
	    			    
	    			    $url = ApiRequestBuilder::chooseAssetUrl($mediaAsset);
	    			    $this->yt->downloadUrlTmp($url, $basename, false);
	    			    
	    			}
	    			
	    		}
	    	}

	    	if (true === $this->uploadVideos || true === $this->updateVideoTitles) {
		    	if (true === $this->includeParentInChildrenTitles && is_null($parentMetadata)) {
		    		if ($metadata['subType'] === "episode" || $metadata['subType'] === "segment") {
		    			$parentMcId = $this->findParentMediaComponentId($metadata['mediaComponentId']);
		    			$parentMetadata = $this->api->mediaComponent($parentMcId, null, "en");
		    		}
		    	}
	    	}
	    	
	    	if (true === $this->uploadVideos) {
	    		if (!array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    			
	    			$output->writeln("Uploading video [".$metadata['mediaComponentId']."] in language [".$languageMetadata['name']."]");
					
					$defaultLanguage = 'en';
					$title = "";
					$description = "";
					$tags = [];
					$localizations = [];

					if (!Utils::isEmpty($this->languagesTitles)) {
						if (isset($this->languagesTitles[$languageMetadata['languageId']])) {
							
							// Until we get clearer requirements from Howard on how to build titles
							// and descriptions using building blocks such as native/en film title,
							// native/en language name, etc., we only use the incoming "title" and/or
							// "description" values from the file as-is:  

							foreach ($this->languagesTitles[$languageMetadata['languageId']] as $bcp47 => $languageInfo) {

								if (!Utils::isEmpty($languageInfo['title']) && true !== $languageInfo['istitleyoutubeready']) {
									throw new \Exception("languagestitlesfile contains title values that are not Youtube ready (unsupported condition)");
								} else if (!Utils::isEmpty($languageInfo['description']) && true !== $languageInfo['isdescriptionyoutubeready']) {
									throw new \Exception("languagestitlesfile contains description values that are not Youtube ready (unsupported condition)");
								}

								$t = $languageInfo['title'];

								$d = $languageInfo['description'];
								if (Utils::isEmpty($d)) {
									$d = $this->buildDescription($metadata, $parentMetadata, $metadata['title'], 'en');
								}

								$ts = $languageInfo['tags'];
								if (Utils::isEmpty($ts)) {
									$ts = $this->buildTags($metadata, $parentMetadata, $languageMetadata, 'en');
								}

								if (true === $languageInfo['isnativelocale']) {
									$defaultLanguage = $bcp47;
									$languageMetadata['youtube_locale'] = $bcp47;
									$title = $t;
									$description = $d;
									$tags = $ts;
								} else {
									$localizations[$bcp47] = [ $title = $t, $description = $d, $tags = $ts ];
								}

 							}

						} else {
							throw new \Exception("languagestitlesfile does not contain title information for language ID: ".$languageMetadata['languageId']);
						}

					} else {
                        $title = $this->buildTitle($metadata, $parentMetadata, $languageMetadata, $defaultLanguage);
                        $description = $this->buildDescription($metadata, $parentMetadata, $title, $defaultLanguage);
                        $tags = $this->buildTags($metadata, $parentMetadata, $languageMetadata, $defaultLanguage);

                        $localizations = $this->buildLocalizations($metadata, $parentMetadata, $languageMetadata, $title);

                        // TODO:  going to try using the audio language as the default
                        // title/description language (per Melissa Immel 2020-11-19):
                        if (isset($localizations[$languageMetadata['youtube_locale']])) {
                            $localizations[$defaultLanguage] = ['title' => $title, 'description' => $description];

                            $defaultLanguage = $languageMetadata['youtube_locale'];
                            $title = $localizations[$defaultLanguage]['title'];
                            $description = $localizations[$defaultLanguage]['description'];

                            unset($localizations[$defaultLanguage]);
                        }
                    }
    			    
					// $output->writeln("default audio language: ".$languageMetadata['youtube_locale']);
    			    // $output->writeln("title ($defaultLanguage locale):");
    			    // $output->writeln("\t".$title);
    			    
    			    // $output->writeln("Localizations:");
    			    // foreach ($localizations as $tag => $l) {
    			    //     $output->writeln("\t$tag:\t".$l['title']);
    			    // }
    			    
    			    // $output->writeln("");
    			    // $output->writeln("description ($defaultLanguage locale)");
    			    // $output->writeln("");
    			    // $output->writeln($description);
    			    
    			    // $output->writeln("");
    			    // $output->writeln("DESCRIPTION LOCALIZATION (tr):");
    			    // $output->writeln("");
    			    // $output->writeln($localizations["tr"]["description"] ?? "");

    			    // $output->writeln("");
    			    // $output->writeln("DESCRIPTION LOCALIZATION (en):");
    			    // $output->writeln("");
    			    // $output->writeln($localizations["en"]["description"] ?? "");
    			    
    			    // $output->writeln("");
					// $output->writeln("tags ($defaultLanguage):  ".var_export($tags, true));
    			    // $output->writeln("localization locales:  ".var_export(array_keys($localizations), true));
    			    // $output->writeln("Privacy status:  ".$this->privacyStatus);
    			    // $output->writeln("");
    			    // $output->writeln("");
    			    
    			    // return;
    			    
    			    $url = null;
    			    $isMasterAssetUrl = null;
    			    if (!Utils::isEmpty($mediaAsset['masterAssetUrl'])) {
        			    $url = $mediaAsset['masterAssetUrl'];
        			    $isMasterAssetUrl = true;
    			    } else {
    			        $url = ApiRequestBuilder::chooseAssetUrl($mediaAsset);
    			        $isMasterAssetUrl = false;
					}
					
					$notifySubscribers = is_null($parentMetadata) && strtolower($this->privacyStatus) === "public";
    			    
	    			$shareUniqueId = $this->yt
	    				->reset()
	    				->setVideoUploadBasicInfo($title, $description)
	    				->setVideoUploadTags($tags)
	    				->setVideoUploadLanguages($defaultLanguage, $languageMetadata['youtube_locale'])
//	    				->setVideoLocation($languageMetadata['locationLatitude'], $languageMetadata['locationLongitude'], $languageMetadata['locationDescription'])
	    				->setVideoUploadSourceInfo($url, $mediaAsset['refId'], $isMasterAssetUrl)
	    				->setVideoOrPlaylistPrivacyStatus($this->privacyStatus)
						->setVideoPublishAt($this->publishAt)
	    				->setVideoUploadLocalizations($localizations)
	    			    ->uploadVideo($notifySubscribers);  // Maybe true for no-parent videos only?
//	    			    ->uploadVideo(false);
	    			
	    			$output->writeln("Video uploaded with 'notify subscribers' set to:  ".var_export($notifySubscribers, true));
	    			
//	    			$shareUniqueId = $this->yt->uploadVideo($metadata, $languageMetadata, $mediaAsset);
	    			if (!is_null($shareUniqueId)) {
	    				
	    				$permalinkUrl = "https://youtu.be/$shareUniqueId";
	    				$mediaLocationShareId = $this->shareQuery->insertMediaLocationSocial($shareUniqueId, $mediaAsset, $permalinkUrl);
	    				
	    				$this->videosInChannel[$mediaAsset['mediaAssetId']] = [
	    						'shareUniqueId' => $shareUniqueId,
	    						'mediaLocationShareId' => $mediaLocationShareId,
	    						'mediaComponentId' => $metadata['mediaComponentId'],
	    						'languageId' => $languageMetadata['languageId']
	    				];
	    				
	    				$imageUrl = ApiRequestBuilder::chooseThumbnailUrl($metadata);
	    				$this->yt->uploadVideoThumbnail($shareUniqueId, $imageUrl);
	    			}
	    			
	    		} else {
	    			$output->writeln("Video [".$mediaAsset['refId']."] already exists on target page.  Skipping upload...");
	    		}
	    	}
	    	
	    	if (true === $this->updateVideoTitles) {
	    		if (array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    		    
					// =============
					// 2021-04-01
					// TODO VERY TEMPORARY.  JUST A HACK TO GET THE DESCRIPTIONS UPDATED
					// FOR CHOSEN WITNESS:
					// $description = "An unlikely woman's life is dramatically transformed by a man who will soon change the world forever. In this animated short film, experience the life of Jesus through the eyes of one of his followers, Mary Magdalene.";
					// $this->yt->updateVideoDescription($shareUniqueId, $description);

					// return;

					// =============


	    		    $title = "";
	    		    $description = $metadata['longDescription'];
	    		    $tags = array($metadata['subType']);
	    		    
	    		    if (is_null($parentMetadata)) {
	    		        $output->writeln("Skipping [".$metadata['mediaComponentId']."] since it is a 'parent' feature and we are only updating clip titles at the moment...");
	    		        return;
	    		    }
	    		    
	    		    if (true === $this->includeParentInChildrenTitles && !is_null($parentMetadata)) {
	    		        
	    		        if ("2_GOJ-0-0" === $parentMetadata['mediaComponentId']) {
	    		            
	    		            $parentTitle = $parentMetadata['title'];
	    		            $currentTitle = $metadata['title'];
	    		            $languageName = $languageMetadata['name'];
	    		            $osisBibleBooks = null;
	    		            
	    		            if (isset($this->langToDescLang[$languageMetadata['languageId']])) {
	    		                
	    		                $tag = $this->langToDescLang[$languageMetadata['languageId']][0];
	    		                
	    		                $mc = $this->api->mediaComponent($metadata['mediaComponentId'], null, "$tag,en");
	    		                $currentTitle = $mc['title'];
	    		                $description = $mc['longDescription'];
	    		                
	    		                $mc = $this->api->mediaComponent($parentMetadata['mediaComponentId'], null, "$tag,en");
	    		                $parentTitle = $mc['title'];
	    		                
	    		                $ml = $this->api->mediaLanguage($languageMetadata['languageId'], "$tag,en");
	    		                $languageName = $ml['name'];
	    		                
	    		                $osisBibleBooks = $this->api->osisBibleBookTerms("$tag,en");
	    		                
	    		            } else {
	    		                $osisBibleBooks = $this->api->osisBibleBookTerms();
	    		            }
	    		            
    	    		        $posOfTotal = $metadata['containsPosition'] . "/" . $metadata['containsCount'];
    	    		        $citation = self::buildBibleCitation($metadata['bibleCitations'][0] ?? null, $osisBibleBooks);
    	    		        
    	    		        // Build title per recipe from Howard:
    	    		        // current title | bible passage ch:verses | feature film name | language | clip number/total (HD)(CC)
    	    		        $title = $currentTitle;
    	    		        
    	    		        if (!Utils::isEmpty($citation)) {
    	    		            $title .= " | " . $citation;
    	    		        }
    	    		        
    	    		        $title .= " | " . $parentTitle . " | " . $languageName . " | " . $posOfTotal . " (HD)";
	    		        
	    		        } else {
	    		            
	    		            $title = $parentMetadata['title'] . ", (" . $languageMetadata['name'] . "), " . $metadata['title'];
	    		            
	    		        }
	    		        
	    		    } else {
	    		        $title = $metadata['title'] . " (" . $languageMetadata['name'] . ")";
	    		    }
	    		    
	    		    if ("1_jf-0-0" === $metadata['mediaComponentId'] ||
	    		        (isset($parentMetadata['mediaComponentId']) && "1_jf-0-0" === $parentMetadata['mediaComponentId']) ||
	    		        (isset($parentMetadata['mediaComponentId']) && "1_wjv-0-0" === $parentMetadata['mediaComponentId'])) {
	    		            
    		            $description .= "\n\nDownload the App for more clips and to take Jesus with you wherever you go: 
https://nextstep.is/getapp

Have questions about Jesus? Click here to learn more: https://nextstep.is/jesusfilmwebsite

Follow Jesus Film Project on Facebook: https://nextstep.is/facebook
Like Jesus Film Project on Instagram:https://nextstep.is/instagram
Follow Jesus Film Project on Twitter: https://nextstep.is/twitter

Since 1979, Jesus Film Project has been known for its diverse library of gospel-centric media that brings people face to face with Jesus all over the world. From city to shore and jungle to township, Jesus Film Project is standing by you with content in over 1800 languages.

Be sure to download our app to watch the full-feature film in addition to hundreds of other films, for free. https://nextstep.is/getapp

You can also become involved in the ministry by participating on short-term mission trips, linking arms with us in prayer, giving to global initiatives, and internships using your major, to share the Gospel with others around the world! https://nextstep.is/how-to-help

To find more videos from Jesus Film Project, Click this link to find the movie playlist in your language: https://www.youtube.com/user/jesusfilm/playlists?view=50&sort=dd&shelf_id=11";

    		        }
    		        
    		        if ("1_jf-0-0" === $metadata['mediaComponentId'] ||
    		            (isset($parentMetadata['mediaComponentId']) && "1_jf-0-0" === $parentMetadata['mediaComponentId'])) {
		                
		                $tags = explode(", ", "Jesus film project, The Jesus film, jesus, jesus is the one, jesus loves me, jesus christ, christian, jesus christ (deity), christianity, christ, the bible (religious text), god, gospel, religion, messiah, praise, worship, crucifixion, Jesus documentary, the bible, truth, movie, story of jesus, jesus story, new testament, ministry, blessed, blessed assurance");
		            }
	    		            
	    			$videoOnPage = $this->videosInChannel[$mediaAsset['mediaAssetId']];
	    			$shareUniqueId = $videoOnPage['shareUniqueId'];
	    			
//	    			$output->writeln("==========================");
	    			$output->writeln($metadata['mediaComponentId'] . ":  \t" . $title);
//	    			$output->writeln("==========================");
//	    			$output->writeln($description);
//	    			$output->writeln("==========================");
	    			
	    			$this->yt->updateVideoTitleDescription($shareUniqueId, $title, $description);
	    		}
	    	}
	    	
	    	if (true === $this->updateVideoStatus) {
	    	    
	    	    if (array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    	        
	    	        $videoOnPage = $this->videosInChannel[$mediaAsset['mediaAssetId']];
	    	        $shareUniqueId = $videoOnPage['shareUniqueId'];
	    	        
	    	        $output->writeln($metadata['mediaComponentId'] . ":  \t" . $this->privacyStatus);
	    	        
	    	        $this->yt
	    	          ->reset()
	    	          ->setVideoOrPlaylistPrivacyStatus($this->privacyStatus)
	    	          ->updateVideoStatus($shareUniqueId);
	    	        
	    	    } else {
	    	        $output->writeln("Video [".$mediaAsset['refId']."] does not exist on target page.  Skipping update video status...");
	    	    }
	    	}
	    	
	    	if (true === $this->updateVideoLocation) {
	    	    
	    	    if (array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    	        $videoOnPage = $this->videosInChannel[$mediaAsset['mediaAssetId']];
	    	        $shareUniqueId = $videoOnPage['shareUniqueId'];
	    	        
	    	        $output->writeln($metadata['mediaComponentId'] . ":  \t" . $languageMetadata['locationDescription']);
	    	        
	    	        $this->yt->updateVideoLocation(
	    	            $shareUniqueId, 
	    	            $languageMetadata['locationLatitude'], 
	    	            $languageMetadata['locationLongitude'], 
	    	            $languageMetadata['locationDescription']);
	    	        
	    	    } else {
	    	        $output->writeln("Video [".$mediaAsset['refId']."] does not exist on target page.  Skipping update location...");
	    	    }
	    	}
	    	
	    	if (true === $this->uploadLocalizations) {
	    	    if (array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    	        
	    	        $output->writeln("Uploading video localizations [".$metadata['mediaComponentId']."] in language [".$languageMetadata['name']."]");
	    	        
	    	        $videoOnPage = $this->videosInChannel[$mediaAsset['mediaAssetId']];
	    	        $shareUniqueId = $videoOnPage['shareUniqueId'];
	    	        
	    	        try {
	    	            
	    	            $title = $this->buildTitle($metadata, $parentMetadata, $languageMetadata, "en");
						$description = $this->buildDescription($metadata, $parentMetadata, $title, "en");
						$localizations = $this->buildLocalizations($metadata, $parentMetadata, $languageMetadata, $title);
						
						$localizations["en"] = [ 'title' => $title, 'description' => $description ];

						$defaultLanguage = $languageMetadata['youtube_locale'];

						$title = $localizations[$defaultLanguage]['title'];
						$description = $localizations[$defaultLanguage]['description'];

						unset($localizations[$defaultLanguage]);

						// $output->writeln("Video language:");
    			    	// $output->writeln("\t$defaultLanguage:\t".$title);
    			    
						// $output->writeln("Localizations:");
						// foreach ($localizations as $tag => $l) {
						// 	$output->writeln("\t$tag:\t".$l['title']);
						// }

						// return;
	    	            
	    	            $this->yt
    	    	            ->reset()
    	    	            ->setVideoUploadLocalizations($localizations)
    	    	            ->uploadVideoLocalizations($shareUniqueId);
	    	            
	    	        } catch (\Google_Service_Exception $ex) {
	    	            if ($ex->getCode() == 403) {
	    	                $output->writeln("Unable to update localizations for [".$mediaAsset['refId']."].  Perhaps due to something about the localization data?  Continuing with next...");
	    	            } else {
	    	                throw $ex;
	    	            }
	    	        }
	    	        
	    	    } else {
	    	        $output->writeln("Video [".$mediaAsset['refId']."] does not exist on target page.  Skipping upload localizations...");
	    	    }
	    	}
    }
    
    protected static function buildBibleCitation(?array $bibleCitation, array $osisBibleBooks) {
        
        $citation = null;
        
        if (Utils::isEmpty($bibleCitation)) {
            return $citation;
        }
        
        if (isset($osisBibleBooks[$bibleCitation['osisBibleBook']]['label'])) {
            
            $bibleBookLabel = $osisBibleBooks[$bibleCitation['osisBibleBook']]['label'];
            $chapterStart = $bibleCitation['chapterStart'];
            $chapterEnd = $bibleCitation['chapterEnd'];
            $verseStart = $bibleCitation['verseStart'];
            $verseEnd = $bibleCitation['verseEnd'];
            
            $citation = "$bibleBookLabel";
            
            if (!Utils::isEmpty($chapterStart)) {
                
                $citation .= " " . $chapterStart;
                
                if (!Utils::isEmpty($verseStart)) {
                    
                    $citation .= ":" . $verseStart;
                    
                    if (Utils::isEmpty($chapterEnd)) {
                        if (!Utils::isEmpty($verseEnd)) {
                            
                            $citation .= "–" . $verseEnd;
                            
                        }
                    } else {
                        
                        $citation .= "–" . $chapterEnd;
                        
                        if (!Utils::isEmpty($verseEnd)) {
                            
                            $citation .= ":" . $verseEnd;
                            
                        }
                    }
                    
                }
            }
            
        } else {
            throw new \Exception("Unable to find bible book label for osis bible book code [".$bibleCitation['osisBibleBook']."]");
        }
        
        return $citation;
    }
    
    protected function buildTitle(array $metadata, ?array $parentMetadata, array $languageMetadataEn, string $tag = null) {
                
        // $metadata is in $tag language
        // $parentMetadata is in $tag language
        // $languageMetadataEn is in "en" language
        
        // New title format as of 2020-12-1
        // JESÚS ► Español (América Latina)(es-419) ► Película oficial de Jesús (Spanish) Latin America (HD)(CC)
        // [title in audio language] ► [language name in audio language] ([bcp47 code]) ► [title in tag language]([language name in tag language]]) (HD)(CC)
        
        $allTags = $this->api->allMetadataLanguageTags();
        $audioLanguageTag = $languageMetadataEn['bcp47'];

		
		
		$titleNameInAudioLanguage = null;
		if ("1_jf-0-0" === $metadata['mediaComponentId'] && $audioLanguageTag === 'npi') {
			$titleNameInAudioLanguage = 'येशू';
		} else if ("1_jf-0-0" === $metadata['mediaComponentId'] && $audioLanguageTag === 'sr') {
			$titleNameInAudioLanguage = 'Исусе';		
		} else if ("1_jf-0-0" === $metadata['mediaComponentId'] && $audioLanguageTag === 'my') {
			$titleNameInAudioLanguage = 'ယေရှု';
		} else {
			if ($audioLanguageTag !== $metadata['metadataLanguageTag']) {
				$mc = $this->api->mediaComponent($metadata['mediaComponentId'], null, "{$audioLanguageTag},en");
				$titleNameInAudioLanguage = $mc['title'];
			} else {
				$titleNameInAudioLanguage = $metadata['title'];
			}
		}
		
		$languageNameInAudioLanguage = null;
		if ($audioLanguageTag === 'my') {
			$languageNameInAudioLanguage = 'ဗမာ';
		} else {
			$languageNameInAudioLanguage = $languageMetadataEn['nameNative'];
		}
		
		$titleNameInTagLanguage = null;
		if ($tag !== $metadata['metadataLanguageTag']) {
			$mc = $this->api->mediaComponent($metadata['mediaComponentId'], null, "{$tag},en");
			$titleNameInTagLanguage = $mc['title'];
		} else {
			$titleNameInTagLanguage = $metadata['title'];
		}
		
		$languageNameInTagLanguage = null;
		if ($tag !== $audioLanguageTag) {
			$ml = $this->api->mediaLanguage($languageMetadataEn['languageId'], "{$tag},en");
			$languageNameInTagLanguage = $ml['name'];
		} else {
			$languageNameInTagLanguage = $languageMetadataEn['name'];
		}


        
		$title = null;
		$parentTitle = $parentMetadata['title'];
		if ("1_wl7-0-0" === $parentMetadata['mediaComponentId']) {
			$parentTitle = "Magdalena" /*. " ► " . $parentMetadata['title'] */;
		}
        
        $metadataLanguageTags = null;
        if (is_null($tag) || "en" === $tag) {
            $metadataLanguageTags = "en";
        } else {
            $metadataLanguageTags = "$tag,en";
        }
        
        $subTypeTerms = $this->api->subTypeTerms($metadataLanguageTags);
        
        // Using "en" language name in all cases
        $languageNameLabel = "";
        if ($languageMetadataEn["nameNative"] !== $languageMetadataEn["name"]) {
            $languageNameLabel = $languageMetadataEn['nameNative'] . " (" . $languageMetadataEn["name"] . ")";
        } else {
            $languageNameLabel = $languageMetadataEn["name"];
        }
        
        $languageNameLabelJf = "in " . $languageMetadataEn["name"] . " voice";
        
        
        
        if ("en" === $metadataLanguageTags) {
            
            if (true === $this->includeParentInChildrenTitles && !is_null($parentMetadata)) {
                // Child title:
                if ("1_jf-0-0" === $parentMetadata['mediaComponentId']) {
                    $position = $metadata['containsPosition'];
                    $count = $metadata['containsCount'];
                    
                    $title = "The JESUS Film clip • \"" . $metadata['title'] . "\" • clip $position of $count clips • " . $languageNameLabelJf;
                } else {
                    $posOfTotal = $metadata['containsPosition'] . "/" . $metadata['containsCount'];
                    $title = $metadata['title'] . " ► " . $languageMetadataEn['name'] . " (".$audioLanguageTag.")" . " ► " . $parentTitle . " ► " . $posOfTotal . " " . $languageMetadataEn["name"]." (HD)(CC)";
                }
            } else {
                // Parent title:
                if ("1_jf-0-0" === $metadata['mediaComponentId']) {
                    $title = $titleNameInAudioLanguage . " ► " . $languageNameInAudioLanguage . " ($audioLanguageTag)" . " ► $titleNameInTagLanguage ($languageNameInTagLanguage) (HD)(CC)";
                } else {
                    $subTypeLabel = $subTypeTerms[$metadata['subType']]['label'];
                    $title = $titleNameInAudioLanguage . " ► " . $languageNameInAudioLanguage . " ($audioLanguageTag)" . " ► $titleNameInTagLanguage ($languageNameInTagLanguage) (HD)(CC)";
                }
            }
            
        } else {
            
            if (true === $this->includeParentInChildrenTitles && !is_null($parentMetadata)) {

                // Child title:
                $posOfTotal = $metadata['containsPosition'] . "/" . $metadata['containsCount'];
				$title = $metadata['title'] . " ► " . $languageNameInTagLanguage . " ($audioLanguageTag)" . " ► " . $parentTitle . " ► " . $posOfTotal . " " . $languageMetadataEn["name"]." (HD)(CC)";

			} else {
                // Parent title:
                $subTypeLabel = $subTypeTerms[$metadata['subType']]['label'];
                $title = $titleNameInAudioLanguage . " ► " . $languageNameInAudioLanguage . " ($audioLanguageTag)" . " ► $titleNameInTagLanguage ($languageNameInTagLanguage) (HD)(CC)";
            }
            
        }
        
        return $title;
    }
    
    protected function buildDescription(array $metadata, ?array $parentMetadata, string $youtubeTitleEn, string $tag = null) {

        $description = "";
        
        if ("1_jf-0-0" === $metadata['mediaComponentId'] ||
            (isset($parentMetadata['mediaComponentId']) && "1_jf-0-0" === $parentMetadata['mediaComponentId'])) {
            
            if (!Utils::isEmpty($this->playlistId)) {
                $description = $this->getBoilerplateJfDescriptionTextPlaylist($this->playlistId) . "\n\n"; 
            }
        }
            
        $description .= $metadata['longDescription'];
        
        if (!Utils::isEmpty($tag) && $tag !== "en") {
            // If non-"en" locale, include the "en" title:
//            $description .= "\n\n" . $youtubeTitleEn;
        }
        
        if ("1_jf-0-0" === $metadata['mediaComponentId'] ||
            (isset($parentMetadata['mediaComponentId']) && "1_jf-0-0" === $parentMetadata['mediaComponentId']) ||
            (isset($parentMetadata['mediaComponentId']) && "1_wjv-0-0" === $parentMetadata['mediaComponentId'])) {
            
            $description .= "\n\n" . $this->getBoilerplateJfDescriptionTextAfter();
        }
        
        return $description;
    }
    
    protected function getBoilerplateJfDescriptionTextAfterOrig() {
        
        $description = "Download the App for more clips and to take Jesus with you wherever you go:
https://nextstep.is/getapp

Have questions about Jesus? Click here to learn more: https://nextstep.is/jesusfilmwebsite

Follow Jesus Film Project on Facebook: https://nextstep.is/facebook
Like Jesus Film Project on Instagram:https://nextstep.is/instagram
Follow Jesus Film Project on Twitter: https://nextstep.is/twitter

Since 1979, Jesus Film Project has been known for its diverse library of gospel-centric media that brings people face to face with Jesus all over the world. From city to shore and jungle to township, Jesus Film Project is standing by you with content in over 1800 languages.

Be sure to download our app to watch the full-feature film in addition to hundreds of other films, for free. https://nextstep.is/getapp

You can also become involved in the ministry by participating on short-term mission trips, linking arms with us in prayer, giving to global initiatives, and internships using your major, to share the Gospel with others around the world! https://nextstep.is/how-to-help

To find more videos from Jesus Film Project, Click this link to find the movie playlist in your language: https://www.youtube.com/user/jesusfilm/playlists?view=50&sort=dd&shelf_id=11";

        return $description;
    }
    
    protected function getBoilerplateJfDescriptionTextPlaylist(string $playlistId) {
        $description = "See the entire playlist, including the feature film here:  https://www.youtube.com/playlist?list=$playlistId";
        return $description;
    }
    
    protected function getBoilerplateJfDescriptionTextAfter() {
        
        $description = "Download the App for more clips and to take Jesus with you wherever you go:
https://nextstep.is/getapp

Have questions about Jesus? Click here to learn more: https://nextstep.is/jesusfilmwebsite

Follow Jesus Film Project on Facebook: https://nextstep.is/facebook
Like Jesus Film Project on Instagram:https://nextstep.is/instagram
Follow Jesus Film Project on Twitter: https://nextstep.is/twitter

Since 1979, Jesus Film Project has been known for its diverse library of gospel-centric media that brings people face to face with Jesus all over the world. From city to shore and jungle to township, Jesus Film Project is standing by you with content in over 1800 languages.

Be sure to download our app to watch the full-feature film in addition to hundreds of other films, for free. https://nextstep.is/getapp

You can also become involved in the ministry by participating on short-term mission trips, linking arms with us in prayer, giving to global initiatives, and internships using your major, to share the Gospel with others around the world! https://nextstep.is/how-to-help";

        return $description;
    }
    
    protected function buildTags(array $metadata, ?array $parentMetadata, array $languageMetadata, string $tag = null) {
        
        $tags = array($metadata['subType']);
        
        if ("1_jf-0-0" === $metadata['mediaComponentId'] ||
            (isset($parentMetadata['mediaComponentId']) && "1_jf-0-0" === $parentMetadata['mediaComponentId'])) {
            
            $tags = explode(", ", "Jesus film project, The Jesus film, jesus, jesus is the one, jesus loves me, jesus christ, christian, jesus christ (deity), christianity, christ, the bible (religious text), god, gospel, religion, messiah, praise, worship, crucifixion, Jesus documentary, the bible, truth, movie, story of jesus, jesus story, new testament, ministry, blessed, blessed assurance");
        }
        
        return $tags;
    }
    
    protected function buildLocalizations(array $metadataEn, ?array $parentMetadataEn, array $languageMetadataEn, string $youtubeTitleEn, $localeFilter = null)
    {
        $localizations = array();
        $metadataLanguageTags = $this->api->allMetadataLanguageTags();
        
        if (!in_array($languageMetadataEn['bcp47'], array_column($metadataLanguageTags, "tag"))) {
        	$metadataLanguageTags[] = [ 'tag' => $languageMetadataEn['bcp47'] ];
        }
        
        foreach ($metadataLanguageTags as $tagProperties) {
            $tag = $tagProperties['tag'];
            if ("en" == strtolower($tag)) {
                continue;
            }
            
            if (!is_null($localeFilter) && strtolower($localeFilter) !== strtolower($tag)) {
                continue;
            }
            
            $mc = $this->api->mediaComponent($metadataEn['mediaComponentId'], null, "$tag,en");
            $parentMc = !is_null($parentMetadataEn) ? $this->api->mediaComponent($parentMetadataEn['mediaComponentId'], null, "$tag,en") : null;
            
            if (true === $this->includeParentInChildrenTitles && !is_null($parentMetadataEn)) {
                $mc['containsPosition'] = $metadataEn['containsPosition'];
                $mc['containsCount'] = $metadataEn['containsCount'];
            }
            
            // Only output if metadata is really non-EN
            if ($mc['metadataLanguageTag'] == $tag || $tag === $languageMetadataEn['bcp47']) {
                $title = $this->buildTitle($mc, $parentMc, $languageMetadataEn, $tag);
                $description = $this->buildDescription($mc, $parentMc, $youtubeTitleEn, $tag);
                
                // TODO:  tried 100, but the FallingPlates "ja" title kept resulting in
                // a YouTube api exception:  [defaultLanguageNotSet], [The request is trying
                // to add localized video details without specifying the default language
                // of the video details].
                $localizations[$tag] = [ 
                    'title' => $title, 
                    'description' => $description 
                ];
                
                // TODO: localized tags?
            }
        }
        
        return $localizations;
    }
    
    protected function buildMediaComponentLocalizations($mediaComponentId, $locale = null)
    {
	    	$localizations = array();
	    	$metadataLanguageTags = $this->api->allMetadataLanguageTags();
	    	
	    	foreach ($metadataLanguageTags as $tagProperties) {
	    		$tag = $tagProperties['tag'];
	    		if ("en" == strtolower($tag)) {
	    			continue;
	    		}
	    		
	    		if (!is_null($locale) && strtolower($locale) !== strtolower($tag)) {
	    		    continue;
	    		}
	    		
	    		$mc = $this->api->mediaComponent($mediaComponentId, null, "$tag,en");
	    		
	    		// Only output if metadata is really non-EN
	    		if ($mc['metadataLanguageTag'] == $tag) {	  
	    			// TODO:  tried 100, but the FallingPlates "ja" title kept resulting in 
	    			// a YouTube api exception:  [defaultLanguageNotSet], [The request is trying 
	    			// to add localized video details without specifying the default language 
	    			// of the video details].
	    			$localizations[$tag] = [ 'title' => $mc['title'], 'description' => $mc['longDescription'] ];
	    		}
	    	}
	    	
	    	return $localizations;
    }
    
    protected function processVideoCaptions($mediaAsset, $parentMetadata, OutputInterface $output)
    {
	    	if (true === $this->deleteCaptions) {
	    		if (array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    			$vid = $this->videosInChannel[$mediaAsset['mediaAssetId']];
	    			
	    			$output->writeln("Deleting captions for YouTube row ID [".$vid['mediaLocationShareId'].
	    					"] video ID [".$vid['shareUniqueId'].
	    					"] and media component ID [".$vid['mediaComponentId']."]");
	    			
	    			$captionsDB = $this->shareQuery->getMediaLocationSocialCaptionsFromDB($vid['mediaLocationShareId']);
	    			foreach ($captionsDB as $captionDB) {
	    				
	    				$this->yt->deleteCaption($captionDB['share_unique_id']);
	    				$this->shareQuery->deleteMediaLocationSocialCaptionFromDB($captionDB['id']);
	    				
	    				$output->writeln("\t Deleted [".$captionDB['share_locale']."]:  ".$captionDB['share_unique_id']);
	    			}
	    		} else {
	    			$output->writeln("Video [".$mediaAsset['refId']."] does not exist on target page.  Skipping captions delete...");
	    		}
	    	}
	    	
	    	if (true === $this->deleteInvalidCaptions) {
	    		if (array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    			$vid = $this->videosInChannel[$mediaAsset['mediaAssetId']];
	    			
	    			$output->writeln("");
	    			$output->writeln("Deleting invalid captions for YouTube row ID [".$vid['mediaLocationShareId'].
	    					"] video ID [".$vid['shareUniqueId'].
	    					"] media component ID [".$vid['mediaComponentId'].
	    					"] and language ID [".$vid['languageId']."]");
	    			
	    			$captionsDB = $this->shareQuery->getInvalidMediaLocationSocialCaptionsFromDB($vid['mediaLocationShareId']);
	    			foreach ($captionsDB as $captionDB) {
	    				
	    				$this->yt->deleteCaption($captionDB['share_unique_id']);
	    				$this->shareQuery->deleteMediaLocationSocialCaptionFromDB($captionDB['id']);
	    				
	    				$output->writeln("\t Deleted [".$captionDB['share_locale']."]:  ".$captionDB['share_unique_id'].", wess_lang_id: ".$captionDB['wess_lang_id'] ?? 'NULL');
	    			}
	    		} else {
	    			$output->writeln("Video [".$mediaAsset['refId']."] does not exist on target page.  Skipping captions delete...");
	    		}
	    	}
	    	
	    	if (true === $this->reconcileCaptions) {
	    		if (array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    			$vid = $this->videosInChannel[$mediaAsset['mediaAssetId']];
	    			
	    			$captions = $this->yt->listCaptions($vid['shareUniqueId']);
	    			$captionsIdColumn = [];
	    			foreach ($captions as $caption) {
	    				$captionsIdColumn[] = $caption['id'];
	    			}
	    			
	    			$captionsDB = $this->shareQuery->getMediaLocationSocialCaptionsFromDB($vid['mediaLocationShareId']);
	    			$captionsDBIdColumn = array_column($captionsDB, 'share_unique_id');
	    			
	    			$output->writeln("Reconciling captions for YouTube row ID [".$vid['mediaLocationShareId'].
	    					"] video ID [".$vid['shareUniqueId'].
	    					"] media component ID [".$vid['mediaComponentId'].
	    					"] and language ID [".$vid['languageId']."]");
	    			
	    			$captionsNotInDb = [];
	    			foreach ($captions as $caption) {
	    				$name = $caption['snippet']['name'];
	    				$id = $caption['id'];
	    				$language = $caption['snippet']['language'];
	    				if (!in_array($id, $captionsDBIdColumn)) {
	    					$captionsNotInDb[] = $caption;
	    					$output->writeln("\t Caption name: [$name], ID: [$id], language: [$language] not found in DB");
	    					// TODO:  what?  Delete from youtube?  write to db?
	    				}
	    			}
	    			
	    			$captionsNotInYouTube = [];
	    			foreach ($captionsDB as $captionDB) {
	    				$id = $captionDB['id'];
	    				$captionLocale = $captionDB['share_locale'];
	    				$shareUniqueId = $captionDB['share_unique_id'];
	    				if (!in_array($captionDB['share_unique_id'], $captionsIdColumn)) {
	    					$captionsNotInYouTube[] = $captionDB;
	    					$output->writeln("\t Caption ID: [$id], share unique id: [$shareUniqueId], locale: [$captionLocale] not found in YouTube...deleting from DB");
	    					$this->shareQuery->deleteMediaLocationSocialCaptionFromDB($id);
	    				}
	    			}
	    			
	    			$output->writeln("");
	    			
	    		} else {
	    			$output->writeln("Video [".$mediaAsset['refId']."] does not exist on target page.  Skipping captions delete...");
	    		}
	    	}
	    	
	    	if (true === $this->uploadCaptions)  {
	    		if (array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    		    $this->uploadCaptions($mediaAsset, $parentMetadata, $output);
	    		} else {
	    			$output->writeln("Video [".$mediaAsset['refId']."] does not exist on target page.  Skipping captions upload...");
	    		}
	    	}
    }
    
    protected function uploadCaptions($mediaAsset, $parentMetadata, $output)
    {
	    	if (!array_key_exists($mediaAsset['mediaAssetId'], $this->videosInChannel)) {
	    		$output->writeln("Video [".$mediaAsset['refId']."] does not exist on target page.  Skipping captions upload...");
	    		return;
	    	}
	    	
	    	$videoOnPage = $this->videosInChannel[$mediaAsset['mediaAssetId']];
	    	
	    	$shareUniqueId = $videoOnPage['shareUniqueId'];
	    	$mediaLocationShareId = $videoOnPage['mediaLocationShareId'];
	    	
	    	if (!isset($mediaAsset['subtitleUrls']['srt'])) {
	    		$output->writeln("Video [".$mediaAsset['refId']."] does not have captions in Arclight.  Skipping captions upload...");
	    		return;
	    	}
	    	
	    	$uploadedLocales = $this->shareQuery->getUploadedVideoLocalesFromDB($mediaLocationShareId);
	    	$uplaodedWessLangIds = array_column($uploadedLocales, 'wess_lang_id');
	    	$uploadedShareLocales = array_column($uploadedLocales, 'share_locale');
	    	
	    	$ct = 0;
	    	
	    	$subLangs = null;
	    	if ((strtolower("1_jf-0-0") === strtolower($mediaAsset['mediaComponentId']) ||
	    	     strtolower("1_jf-0-0") === strtolower($parentMetadata['mediaComponentId'])) && 
	    	    isset($this->langToSubLang[$mediaAsset['languageId']])) {
	    	    
	    	    $subLangs = $this->langToSubLang[$mediaAsset['languageId']];
	    	}
	    	
	    	$srts = $mediaAsset['subtitleUrls']['srt'];
	    	foreach ($srts as $srt) {
	    		$langId = $srt['languageId'];
	    		
	    		if (!is_null($subLangs) && !in_array($langId, $subLangs)) {
	    		    continue;
	    		}
	    		
	    		if (array_key_exists($langId, $this->youTubeLocales)) {
	    			
	    			$locale = $this->youTubeLocales[$langId]['locale'];
	    			$localeName = $this->youTubeLocales[$langId]['name'];
	    			$url = $srt['url'];
	    			
	    			if (in_array($locale, $uploadedShareLocales) && !in_array($langId, $uplaodedWessLangIds)) {
	    				$output->writeln("*** WARNING ***:  language [$langId] for locale [$locale] apparently missing from database!");
	    				$output->writeln("\t Captions locale [$locale] already uploaded to Youtube.  Skipping...");
	    				continue;
	    			}
	    			
	    			if (in_array($langId, $uplaodedWessLangIds)) {
	    				$output->writeln("\t Captions locale [$locale] (wess lang id [$langId]) already uploaded to Youtube.  Skipping...");
	    				continue;
	    			}
	    			
	    			$output->writeln("\t Uploading subtitle [$langId] for locale [$locale]:  $url");
	    			
	    			//TODO:  leave the locale name off?  So that it doesn't come out
	    			// on YouTube as "Japanese - Japanese"
//	    			help help help
//	    			spanish latin american (21028) should output as "es_419" instead of just "es"
	    			// ===========
	    			$captionId = $this->yt->uploadCaption($shareUniqueId, $url, $locale, $localeName);
//	    			$captionId = $this->yt->uploadCaptionNoDefer($shareUniqueId, $url, $locale, $localeName);
	    			
	    			$mediaLocationShareCaptionId = $this->shareQuery->insertMediaLocationSocialCaption($mediaLocationShareId, $locale, $langId, $captionId);
	    			$uploadedLocales[$mediaLocationShareCaptionId] = [ 'wess_lang_id' => $langId, 'share_locale' => $locale];

	    			sleep(15);
	    		}
	    	}
    }
    
    protected function uploadToPlaylist($parentMetadata, $languageMetadata, OutputInterface $output) 
    {
    		$containsIdsFound = [];
    		$containsIds = $this->api->mediaComponentContains($parentMetadata['mediaComponentId']);
    		$mediaComponentLanguageLinks = $this->api->mediaComponentLanguageLinks();
    		
    		$languageId = $languageMetadata['languageId'];
    	
    		$videosToAdd = [];
    		$videosAdded = [];
    		foreach ($this->videosInChannel as $mediaAssetId => $videoInfo) {
    			if (in_array($videoInfo['mediaComponentId'], $containsIds) &&
    					$videoInfo['languageId'] == $languageId) {
    						
    				$videosToAdd[$videoInfo['mediaLocationShareId']] = $videoInfo['shareUniqueId'];
    				$containsIdsFound[] = $videoInfo['mediaComponentId'];
    			}
    		}
    		
    		$containsIdsNotFound = array_diff($containsIds, $containsIdsFound);
    		$incomplete = false;
    		
    		foreach ($containsIdsNotFound as $notFound) {
    			// If the 'notFound' media component is available in the given
    			// media language, then our list of 'videosInChannel' is incomplete
    			// for this playlist
    			if (in_array($languageId, $mediaComponentLanguageLinks[$notFound])) {
    				$incomplete = true;
    				break;
    			}
    		}
    		
    		if (true === $incomplete) {
    			$output->writeln("NOT creating playlist for [".$parentMetadata['mediaComponentId']."] in language [$languageId] because not all of its children have been uploaded.");
    			return;
    		}
   		
   		list($playlistId, $mediaLocationSharePlaylistId) = $this->shareQuery->getPlaylistFromDB($parentMetadata['mediaComponentId'], $languageId);
   		
   		if (is_null($playlistId)) {
   			
   			$playlistTitle =  $parentMetadata['title'] . " [" . $languageMetadata['name'] . "]";
   			$playlistDescription = $parentMetadata['shortDescription'];
   			
   			$playlistId = $this->yt
   							->reset()
   							->setVideoOrPlaylistPrivacyStatus($this->privacyStatus)
   							->createPlaylist($playlistTitle, $playlistDescription);
		    	
	    		$mediaLocationSharePlaylistId = $this->shareQuery->insertMediaLocationSocialPlaylist(
	    				$playlistId,
	    				$parentMetadata['mediaComponentId'],
	    				$languageMetadata['languageId']);
		    		
		    	$output->writeln("Created playlist: [$playlistId]");
   		} else {
   			$output->writeln("Found playlist in DB: [$playlistId]");
   		}
   		
   		foreach ($videosToAdd as $mediaLocationShareId => $shareUniqueId) {
   			
   			$playlistItemId = $this->yt->createPlaylistItem($shareUniqueId, $playlistId);   			
   			$videosAdded[$mediaLocationShareId] = $playlistItemId;
   			
   			$output->writeln("\t Added video [$shareUniqueId] ([$mediaLocationShareId]) to playlist [$playlistId] ([$mediaLocationSharePlaylistId])");
   		}
	    	
   		$this->shareQuery->insertMediaLocationSocialPlaylistItems($mediaLocationSharePlaylistId, $videosAdded);
    }
    
    // TODO:  this was quick-n-dirty for uploading the missing contentId account videos
    private function findParentMediaComponentId($mediaComponentId) {
    	
    		if (1 === preg_match("@(\d_(0\-)?[A-Za-z]+).*@", $mediaComponentId, $matches)) {
    			$originalPartial = $matches[1];
    				
    			$possibleParents = $this->api->mediaComponentContainedBy($mediaComponentId);
    			$likelyParentId = null;
    			$parentMatchCount = 0;
    				
    			foreach ($possibleParents as $possible) {
    				if (1 === preg_match("@(\d_(0\-)?[A-Za-z]+).*@", $possible, $matches)) {
    					$likelyParentId = $possible;
    					$parentMatchCount++;
    				}
    			}
    				
    			if (is_null($likelyParentId) || $parentMatchCount !== 1) {
    			    // TODO:  Huge hack!
    			    if (in_array("MAG1", $possibleParents)) {
    			        return "MAG1";
    			    }
    				throw new \Exception("Unable to narrow down parent ID for:  ".$mediaComponentId.":  ".implode(", ",$possibleParents));
    			} 
    			
    			return $likelyParentId;
    			
    		} else {
    			throw new \Exception("Unable to parse media component ID [$mediaComponentId] for which to find parent ID");
    		}
	    	
    }
}

