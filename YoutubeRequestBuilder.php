<?php

namespace Arclight\ImportBundle\Utils;

use Symfony\Component\Console\Output\OutputInterface;
use Arclight\MMDBBundle\php\misc\Utils;
use Arclight\MMDBBundle\php\ovp\OvpStorageServiceInterface;

class YouTubeRequestBuilder 
{
	public const CALL_ID_UPDATE_VIDEO_TITLE = "uploadVideoTitle";
	public const CALL_ID_UPDATE_VIDEO_STATUS = "uploadVideoStatus";
	public const CALL_ID_UPDATE_VIDEO_RECORDING_DETAILS = "uploadVideoRecordingDetails";
	public const CALL_ID_UPLOAD_VIDEO_LOCALIZATIONS = "uploadVideoLocalizations";
	public const CALL_ID_UPLOAD_VIDEO_THUMBNAIL = "uploadVideoThumbnail";
	public const CALL_ID_LIST_CHANNELS = "listChannels";
	public const CALL_ID_LIST_PLAYLIST_ITEMS = "listPlaylistItems";
	public const CALL_ID_UPLOAD_VIDEO = "uploadVideo";
	public const CALL_ID_DELETE_CAPTION = "deleteCaption";
	public const CALL_ID_LIST_CAPTIONS = "listCaptions";
	public const CALL_ID_UPLOAD_CAPTION = "uploadCaption";
	public const CALL_ID_CREATE_PLAYLIST = "createPlaylist";
	public const CALL_ID_CREATE_PLAYLIST_ITEM = "createPlaylistItem";
	public const CALL_ID_DELETE_PLAYLIST = "deletePlaylist";
	
	/**
	 * @var YouTubeServiceBuilder
	 */
	protected $yts = null;
	
	/**
	 * @var ArclightShareQueryHelper
	 */
	protected $shareQuery;
	
	/**
	 * @var \Arclight\MMDBBundle\php\ovp\OvpStorageServiceInterface
	 */
	protected $ovpStorageService;
	
	/**
	 * Optional console output interface to be used for error/debug output
	 *
	 * @var OutputInterface
	 */
	protected $output;
	
	protected $videoSourceUrl = null;
	protected $videoSourceRefId = null;
	protected $isVideoSourceUrlMaster = false;
	protected $videoSnippet = null;
	protected $videoLocalizations = null;
	protected $videoOrPlaylistStatus = null;
	protected $videoPublishAt = null;
	protected $videoRecordingDetails = null;
	
	/**
	 * Initializes a new <tt>YouTubeRequestBuilder</tt>.
	 *
	 * @param \GuzzleHttp\Client $client The Guzzle client.
	 */
	public function __construct(YouTubeServiceBuilder $yts, ArclightShareQueryHelper $shareQuery, 
	                            OvpStorageServiceInterface $ovpStorageService, OutputInterface $output)
	{
		$this->yts = $yts;
		$this->shareQuery = $shareQuery;
		$this->ovpStorageService = $ovpStorageService;
		$this->output = $output;
		
		$this->reset();
	}
	
	public function reset() 
	{
		$this->videoSourceUrl = null;
		$this->videoSourceRefId = null;
		$this->isVideoSourceUrlMaster = false;
		$this->videoSnippet = null;
		$this->videoRecordingDetails = null;
		$this->videoLocalizations = [];
		
		$this->videoOrPlaylistStatus = "public";
		
		return $this;
	}

	public function listI18NLanguages(string $locale = null)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataApiKeyService();

		$response = null;
        if (!Utils::isEmpty($locale)) {
			$response = $youTubeService->i18nLanguages->listI18nLanguages('snippet', [ 'hl' => $locale ]);
        } else {
			$response = $youTubeService->i18nLanguages->listI18nLanguages('snippet');
		}

		$i18NLanguages = [];
		foreach ($response->getItems() as $item) {
			$i18NLanguages[$item->getSnippet()->getHl()] = $item->getSnippet()->getName();
		}

		return $i18NLanguages;
	}

	public function listI18NRegions(string $locale = null)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataApiKeyService();

		$response = null;
        if (!Utils::isEmpty($locale)) {
			$response = $youTubeService->i18nRegions->listI18nRegions('snippet', [ 'hl' => $locale ]);
        } else {
			$response = $youTubeService->i18nRegions->listI18nRegions('snippet');
		}

		$i18NRegions = [];
		foreach ($response->getItems() as $item) {
			$i18NRegions[$item->getSnippet()->getGl()] = $item->getSnippet()->getName();
		}

		return $i18NRegions;
	}
	
	public function listVideosByYoutubeId(array $videosInChannel, OutputInterface $output) {
	    
	    $youTubeService = $this->yts->getAuthorizedYouTubeDataService();
	    
	    $youtubeIds = [];
	    foreach ($videosInChannel as $key => $vid) {
	        $youtubeIds[$vid['shareUniqueId']] = $key;
	    }
	    
	    $youtubeIdsAsString = implode(',', array_keys($youtubeIds));
	    
	    $output->writeln("");
	    $output->writeln("Looking up videos in youtube by ids: ".$youtubeIdsAsString);
	    
	    $response = $youTubeService->videos->listVideos("snippet,contentDetails,recordingDetails,localizations,status,statistics", array('id' => $youtubeIdsAsString));
	    if (Utils::isEmpty($response['items'])) {
	        $output->writeln("No matching videos found in channel");
	        return;
	    }
	    
	    foreach ($response['items'] as $video) {
	        /** @var $video \Google_Service_YouTube_Video */
	        $snippet = $video->getSnippet();
	        $contentDetails = $video->getContentDetails();
	        $recordingDetails = $video->getRecordingDetails();
	        $localizations = $video->getLocalizations();
	        $status = $video->getStatus();
	        $statistics = $video->getStatistics();
	        
	        $mediaComponentId = $videosInChannel[$youtubeIds[$video->getId()]]['mediaComponentId'] ?? "UNKNOWN";
	        $languageId = $videosInChannel[$youtubeIds[$video->getId()]]['languageId'] ?? "UNKNOWN";
	        $languageName = $videosInChannel[$youtubeIds[$video->getId()]]['languageName'] ?? "UNKNOWN";
	        
	        $output->writeln("");
	        $output->writeln("Youtube video ID: ".$video->getId());
	        $output->writeln("\tMedia component ID: ".$mediaComponentId);
	        $output->writeln("\tLanguage ID: ".$languageId);
	        $output->writeln("\tLanguage name: ".$languageName);
	        
	        $output->writeln("");
	        $output->writeln("\tSNIPPET:");
	        
	        $output->writeln("\t\tTitle: ".$snippet->getTitle());
	        $output->writeln("\t\tDefault audio language: ".$snippet->getDefaultAudioLanguage());
	        $output->writeln("\t\tDefault language: ".$snippet->getDefaultLanguage());
	        $output->writeln("\t\tPublished at: ".$snippet->getPublishedAt());
	        
	        $output->writeln("");
	        $output->writeln("\tSTATUS:");
	        
	        $output->writeln("\t\tUpload status: ".$status->getUploadStatus());
	        if ("failed" === strtolower($status->getUploadStatus())) {
	            $output->writeln("\t\t\tFailure reason: ".$status->getFailureReason());
	        }
	        if ("rejected" === strtolower($status->getUploadStatus())) {
	            $output->writeln("\t\t\tRejection reason: ".$status->getRejectionReason());
	        }
	        $output->writeln("\t\tPrivacy status: ".$status->getPrivacyStatus());
	        $output->writeln("\t\tLicense: ".$status->getLicense());
	        $output->writeln("\t\tEmbeddable: ".var_export($status->getEmbeddable(), true));
	        $output->writeln("\t\tMade for kids: ".var_export($status->getMadeForKids(), true));
	        $output->writeln("\t\tSelf declared made for kids: ".var_export($status->getSelfDeclaredMadeForKids(), true));
	        
	        $output->writeln("");
	        $output->writeln("\tCONTENT DETAILS:");
	        
	        $output->writeln("\t\tHas custom thumbnail: ".var_export($contentDetails->getHasCustomThumbnail(), true));
	        $output->writeln("\t\tHas captions available: ".$contentDetails->getCaption());
	        if (!is_null($recordingDetails)) {
	            $output->writeln("\t\tRecording date: ".$recordingDetails->getRecordingDate());
	            $output->writeln("\t\tLocation description: ".$recordingDetails->getLocationDescription());
	            
	            $location = $recordingDetails->getLocation();
	            if (!is_null($location)) {
	                $output->writeln("\t\tLocation - latitude: ".$location->getLatitude().", longitude: ".$location->getLongitude());
	            }
	        }
	        
	        $output->writeln("");
	        
	        if (!Utils::isEmpty($localizations)) {
	            $output->writeln("\tLOCALIZATIONS:");
	            foreach ($localizations as $key => $localization) {
	                $output->writeln("\t\t[$key]  title: ".$localization->getTitle());
	            }
	        } else {
	            $output->writeln("\tLOCALIZATIONS:  none");
	        }
	        
	        $output->writeln("");
	        $output->writeln("\tSTATISTICS:");
	        
	        $output->writeln("\t\tComment count: ".$statistics->getCommentCount());
	        $output->writeln("\t\tView count: ".$statistics->getViewCount());
	        $output->writeln("\t\tLike count: ".$statistics->getLikeCount());
	        $output->writeln("\t\tDislike count: ".$statistics->getDislikeCount());
	        $output->writeln("\t\tFavorite count: ".$statistics->getFavoriteCount());
	    }
	}
	
	public function searchListAllMyVideos($videosInChannel, OutputInterface $output) {

		$videosByShareUniqueId = [];
		foreach ($videosInChannel as $mediaAssetId => $shareInfo) {
			$videosByShareUniqueId[$shareInfo["shareUniqueId"]] = $shareInfo;
		}
		
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		$response = $youTubeService->search->listSearch('snippet',
				array('maxResults' => 50, 'forMine' => true, 'type' => 'video'));
		
		$nextPageToken = $response['nextPageToken'];
		$resultsPerPage = intval($response['pageInfo']['resultsPerPage']);
		$totalResults = intval($response['pageInfo']['totalResults']);
		
		$output->writeln("Next page token:  ".$nextPageToken);
		$output->writeln("Results per page: ".$resultsPerPage);
		$output->writeln("Total results: ".$totalResults);
		
		$counter = 0;
		$this->outputPage($response['items'], $counter, $videosByShareUniqueId, $output);
		while ($counter < $totalResults) {
			
			$response = $youTubeService->search->listSearch('snippet',
					array('maxResults' => 50, 'forMine' => true, 'type' => 'video', 'pageToken' => $nextPageToken));
			
			$nextPageToken = $response['nextPageToken'];
			$this->outputPage($response['items'], $counter, $videosByShareUniqueId, $output);
		}
		
		$output->writeln("Total result count output: ".$counter);
	}
	
	private function outputPage($items, &$counter, $videosByShareUniqueId, OutputInterface $output) {
		
		foreach ($items as $item) {
			$videoId = $item['id']['videoId'];
			$channelId = $item['snippet']['channelId'];
			$title = $item['snippet']['title'];
			$publishedAtString = $item['snippet']['publishedAt'];
			$mediaComponentId = '';
			$languageId = '';
			if (array_key_exists($videoId, $videosByShareUniqueId)) {
				$mediaComponentId = $videosByShareUniqueId[$videoId]["mediaComponentId"];
				$languageId = $videosByShareUniqueId[$videoId]["languageId"];
			}
			$publishedAt = \DateTime::createFromFormat("Y-m-d\TH:i:s.u\Z", $publishedAtString, new \DateTimeZone('UTC'));
			$publishedAtString = (false !== $publishedAt) ? $publishedAt->format('Y-m-d') : "";
			
			$output->writeln($videoId."|".$channelId."|".$title."|".$publishedAtString."|".$mediaComponentId."|".$languageId);
//			$output->write("\tVideoId: ".$videoId);
//			$output->write(", channelId: ".$channelId);
//			$output->write(", title: ".$title);
//			$output->writeln(", publishedAt: ".$publishedAt->format('Y-m-d'));
			
			$counter++;
		}
		
	}
	
	public function getChannelIdFromYT($channelTitle)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		$channelsResponse = $youTubeService->channels->listChannels('id,snippet', array(
				'mine' => true,
		));

		$this->shareQuery->logServiceCall(self::CALL_ID_LIST_CHANNELS, true, 3);
		
		foreach ($channelsResponse['items'] as $channel) {
			if ($channel['snippet']['title'] == $channelTitle) {
				return $channel['id'];
			}
		}
		
		$this->output->writeln("Channel ID for title [$channelTitle] not found!");
		return null;
		
	}
	
	public function getVideosInChannelYT($channelId)
	{
		
		$videosInChannel = [];
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		try {
			// Call the channels.list method to retrieve information about the
			// currently authenticated user's channel.
			$channelsResponse = $youTubeService->channels->listChannels('contentDetails', array(
					'mine' => true,
			));
			
			$this->shareQuery->logServiceCall(self::CALL_ID_LIST_CHANNELS, true, 3);
			
			/**
			 * @var $channelsResponse \Google_Service_YouTube_ChannelListResponse
			 */
			$items = $channelsResponse->getItems();
			foreach ($items as $channel) {
				
				echo "Channel: ".$channel->getId().PHP_EOL;
				
				/**
				 * @var $channel \Google_Service_YouTube_Channel
				 */
				$contentDetails = $channel->getContentDetails();
				
				/**
				 * @var $contentDetails \Google_Service_YouTube_ChannelContentDetails
				 */
				$relatedPlaylists = $contentDetails->getRelatedPlaylists();
				
				/**
				 * @var $relatedPlaylists \Google_Service_YouTube_ChannelContentDetailsRelatedPlaylists
				 */
				$uploadsPlaylistId = $relatedPlaylists->getUploads();
				
				$playlistItemsResponse = $youTubeService->playlistItems->listPlaylistItems('snippet', array(
						'playlistId' => $uploadsPlaylistId,
						'maxResults' => 50
				));
				
				$this->shareQuery->logServiceCall(self::CALL_ID_LIST_PLAYLIST_ITEMS, true, 3);
				
				/**
				 * @var $playlistItemsResponse \Google_Service_YouTube_PlaylistItemListResponse
				 */
				$uploadItems = $playlistItemsResponse->getItems();
				
				foreach ($uploadItems as $uploadItem) {
					/**
					 * @var $uploadItem \Google_Service_YouTube_PlaylistItem
					 */
					
					$id = $uploadItem->getSnippet()->getResourceId()->getVideoId();
					$title = $uploadItem->getSnippet()->getTitle();
					
					$videosInChannel[] = ['id' => $id, 'title' => $title];
					echo "\tID: $id, Title: $title".PHP_EOL;
				}
			}
			
			return $videosInChannel;
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			throw $e;
		}
	}
	
	public function getItemsInPlaylistYT(string $playlistId) {
	    
	    $playlistItems = [];
	    $youTubeService = $this->yts->getAuthorizedYouTubeDataService();
	    
	    try {

	        $nextPageToken = null;
	        
	        do {
	            
	            $params = ['playlistId' => $playlistId, 'maxResults' => 50];
	            if (!Utils::isEmpty($nextPageToken)) {
	                $params['pageToken'] = $nextPageToken;
	            }
	            
	            $response = $youTubeService->playlistItems->listPlaylistItems('snippet', $params);
	            
	            /**
	             * @var $response \Google_Service_YouTube_PlaylistItemListResponse
	             */
	            $items = $response->getItems();
	            
	            $responseItems = [];
	            foreach ($items as $item) {
	                
	                /**
	                 * @var $item \Google_Service_YouTube_PlaylistItem
	                 */
	                
	                $id = $item->getId();
	                $videoId = $item->getSnippet()->getResourceId()->getVideoId();
	                $title = $item->getSnippet()->getTitle();
	                $position = $item->getSnippet()->getPosition();
	                
	                $responseItems[] = [ 'id' => $id, 'videoId' => $videoId, 'title' => $title, 'position' => $position ];
	                
	            }
	            
	            $playlistItems = array_merge($playlistItems, $responseItems);
	            $nextPageToken = $response['nextPageToken'] ?? null;
	            
	        } while (!Utils::isEmpty($nextPageToken));
    	    
    	    return $playlistItems;
	    
	    } catch (\Google_Service_Exception $e) {
	        $this->parseLimitsExceeded($e);
	        throw $e;
	    } catch (\Google_Exception $e) {
	        throw $e;
	    }
	}
	
	protected function ensureVideoSnippet() 
	{
		if (is_null($this->videoSnippet)) {
			$this->videoSnippet = new \Google_Service_YouTube_VideoSnippet();
			$this->videoSnippet->setCategoryId("1");  // Film & Animation
		}
		
		return $this->videoSnippet;
	}
	
	protected function ensureVideoRecordingDetails()
	{
	    if (is_null($this->videoRecordingDetails)) {
	        $this->videoRecordingDetails = new \Google_Service_YouTube_VideoRecordingDetails();
	    }
	    
	    return $this->videoRecordingDetails;
	}
	
	public function setVideoUploadBasicInfo($title, $description)
	{
		$snippet = $this->ensureVideoSnippet();
		
		$snippet->setTitle($title);
		$snippet->setDescription($description);
		
		return $this;
	}
	
	public function setVideoUploadTags(array $tags)
	{
		$snippet = $this->ensureVideoSnippet();
		$snippet->setTags($tags);
		
		return $this;
	}
	
	public function setVideoUploadCategoryId($categoryId)
	{
		// Numeric video category. See
		// https://developers.google.com/youtube/v3/docs/videoCategories/list
		// 1  - "Film & Animation"
		// 18 - "Short Movies"
		// 29 - "Nonprofits & Activism"
		// 31 - "Anime/Animation"
		
		$snippet = $this->ensureVideoSnippet();
		$snippet->setCategoryId($categoryId);
		
		return $this;
	}
	
	public function setVideoUploadLanguages($defaultLanguage, $defaultAudioLanguage)
	{
		$snippet = $this->ensureVideoSnippet();
		
		$snippet->setDefaultLanguage($defaultLanguage);
		$snippet->setDefaultAudioLanguage($defaultAudioLanguage);
		
		return $this;
	}
	
	public function setVideoPublishAt(?\DateTime $publishAt) 
	{
		if (null !== $publishAt) {

			// The date and time when the video is scheduled to publish. 
			// Format as ISO 8601 per https://developers.google.com/youtube/v3/docs/videos#status.publishAt:
			$this->videoPublishAt = $publishAt->format(\DateTime::ATOM);
			
			// NOTE:  It can be set only if the privacy status of the video is private. 

			// NOTE:  If you set the publishAt value when calling the 
			// videos.update method, you must also set the status.privacyStatus 
			// property value to private even if the video is already private.

		}

		return $this;
	}
	
	public function setVideoOrPlaylistStatusPrivate() 
	{
		$this->videoOrPlaylistStatus = "private";
		return $this;
	}
	
	public function setVideoOrPlaylistStatusUnlisted()
	{
		$this->videoOrPlaylistStatus= "unlisted";
		return $this;
	}
	
	public function setVideoOrPlaylistStatusPublic()
	{
		$this->videoOrPlaylistStatus= "public";
		return $this;
	}
	
	public function setVideoOrPlaylistPrivacyStatus(string $privacyStatus) {
	    
	    switch ($privacyStatus) {
	        case "public":
	            $this->setVideoOrPlaylistStatusPublic();
	            break;
	        case "unlisted":
	            $this->setVideoOrPlaylistStatusUnlisted();
	            break;
	        case "private":
	            $this->setVideoOrPlaylistStatusPrivate();
	            break;
	        default:
	            throw new \Exception("Invalid privacy status provided [$privacyStatus].  Must be one of:  private, unlisted, public.");
	    }
	    
	    return $this;
	    
	}
	
	public function setVideoLocation(?float $latitude, ?float $longitude, ?string $locationDescription) {
	    
	    if (!is_null($latitude) && !is_null($longitude)) {
	        
	        $recordingDetails = $this->ensureVideoRecordingDetails();
	        
    	    $location = new \Google_Service_YouTube_GeoPoint();
    	    $location->setLatitude($latitude);
    	    $location->setLongitude($longitude);
    	    
    	    $recordingDetails->setLocation($location);
    	    
	    }
	    
	    if (!Utils::isEmpty($locationDescription)) {
	        
	        $recordingDetails = $this->ensureVideoRecordingDetails();
	        $recordingDetails->setLocationDescription($locationDescription);
	        
	    }
	    
	    return $this;
	}
	
	public function setVideoUploadSourceInfo($url, $refId, bool $isVideoSourceUrlMaster)
	{
		$this->videoSourceUrl = $url;
		$this->videoSourceRefId = $refId;
		$this->isVideoSourceUrlMaster = $isVideoSourceUrlMaster;
		return $this;
	}
	
	public function setVideoUploadLocalizations(?array $localizations) 
	{
	    if (Utils::isEmpty($localizations)) {
	        return $this;
	    }
	    
		if (is_null($this->videoLocalizations)) {
			$this->videoLocalizations = [];
		}
		
		foreach ($localizations as $tag => $data) {
			$localization = new \Google_Service_YouTube_VideoLocalization();
			
			$localization->setTitle($data['title']);
			$localization->setDescription($data['description']);
			
			$this->videoLocalizations[$tag] = $localization;
		}
		
		return $this;
	}
	
	public function addVideoUploadLocalization($tag, $title, $description)
	{
		if (is_null($this->videoLocalizations)) {
			$this->videoLocalizations = [];
		}
		
		$localization = new \Google_Service_YouTube_VideoLocalization();
		
		$localization->setTitle($title);
		$localization->setDescription($description);
		
		$this->videoLocalizations[$tag] = $localization;
		
		return $this;
	}

	public function uploadVideo(bool $notifySubscribers = false)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService(true);
		$googleClient = $youTubeService->getClient();
		
		try {
			
			$status = new \Google_Service_YouTube_VideoStatus();
			$status->setPrivacyStatus($this->videoOrPlaylistStatus);
            if (null != $this->videoPublishAt) {
                $status->setPublishAt($this->videoPublishAt);
            }
			
			// Associate the snippet and status objects with a new video resource.a
			$video = new \Google_Service_YouTube_Video();
			$video->setSnippet($this->videoSnippet);
			$video->setStatus($status);
			
			if (!empty($this->videoLocalizations)) {
				$video->setLocalizations($this->videoLocalizations);
			}
			
			if (!is_null($this->videoRecordingDetails)) {
			    $video->setRecordingDetails($this->videoRecordingDetails);
			}
			
			// Specify the size of each chunk of data, in bytes. Set a higher value for
			// reliable connection as fewer chunks lead to faster uploads. Set a lower
			// value for better recovery on less reliable connections.
			$chunkSizeBytes = 50 * 1024 * 1024;
			
			// Setting the defer flag to true tells the client to return a request which can be called
			// with ->execute(); instead of making the API call immediately.
			$googleClient->setDefer(true);
			
			// Create a request for the API's videos.insert method to create and upload the video.
			$insertRequest = $youTubeService->videos->insert("status,snippet,localizations,recordingDetails", $video, [ 'notifySubscribers' => $notifySubscribers ]);
			
			// Create a MediaFileUpload object for resumable uploads.
			$media = new \Google_Http_MediaFileUpload(
					$googleClient,
					$insertRequest,
					'video/*',
					null,
					true,
					$chunkSizeBytes
					);
			
			$localFilePath = $this->downloadUrlTmp($this->videoSourceUrl, $this->videoSourceRefId, $this->isVideoSourceUrlMaster);
			$media->setFileSize(Utils::retrieveContentLength($localFilePath));
			
			$status = $this->uploadFileToGoogle($localFilePath, $chunkSizeBytes, $media);
			
			// Delete the temporary local file
			unlink($localFilePath);
			
			// If you want to make other calls after the file upload, set setDefer back to false
			$googleClient->setDefer(false);
			
			$this->shareQuery->logServiceCall(self::CALL_ID_UPLOAD_VIDEO, true, 1606);
			
			return $status['id'];
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
	}
	
	public function updateVideoDescription(string $videoId, string $description)
	{
		$this->output->writeln("Updating description for video:  ".$videoId);
		
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		$googleClient = $youTubeService->getClient();
		
		try {
			
			$listResponse = $youTubeService->videos->listVideos("snippet", array(
					'id' => $videoId,
			));
			
			if (empty($listResponse)) {
				$this->output->writeln("Unable to find video for updating localizations:  ".$videoId);
				return;
			}
			
			$video = $listResponse[0];
			$video['snippet']['description'] = $description;

			$videoUpdateResponse = $youTubeService->videos->update("snippet", $video);
			
			$this->shareQuery->logServiceCall(self::CALL_ID_UPDATE_VIDEO_TITLE, true, 2);
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
	}
	
	public function updateVideoTitleDescription($videoId, $title, $description = null)
	{
		if (empty($title)) {
			$this->output->writeln("No title with which to update video:  ".$videoId);
		} else {
			$this->output->writeln("Updating title [$title] for video:  ".$videoId);
		}
		
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		$googleClient = $youTubeService->getClient();
		
		try {
			
			$listResponse = $youTubeService->videos->listVideos("snippet", array(
					'id' => $videoId,
			));
			
			if (empty($listResponse)) {
				$this->output->writeln("Unable to find video for updating localizations:  ".$videoId);
				return;
			}
			
			$video = $listResponse[0];
			$video['snippet']['title'] = $title;
			if (!is_null($description)) {
			    $video['snippet']['description'] = $description;
			}

			$videoUpdateResponse = $youTubeService->videos->update("snippet", $video);
			
			$this->shareQuery->logServiceCall(self::CALL_ID_UPDATE_VIDEO_TITLE, true, 2);
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
	}
	
	public function updateVideoStatus($videoId)
	{
	    $this->output->writeln("Updating privacy status [$this->videoOrPlaylistStatus] for video:  ".$videoId);
	    
	    $youTubeService = $this->yts->getAuthorizedYouTubeDataService();
	    
	    try {
	        
	        $listResponse = $youTubeService->videos->listVideos("status", array(
	            'id' => $videoId,
	        ));
	        
	        if (empty($listResponse)) {
	            $this->output->writeln("Unable to find video for updating privacy status:  ".$videoId);
	            return;
	        }
	        
	        $video = $listResponse[0];
	        
	        // TODO:  support other status properties!
	        $video['status']['privacyStatus'] = $this->videoOrPlaylistStatus;
			if (null != $this->videoPublishAt) {
				$video['status']['publishAt'] = $this->videoPublishAt;
			}
	        
	        $videoUpdateResponse = $youTubeService->videos->update("status", $video);
	        
	        $this->shareQuery->logServiceCall(self::CALL_ID_UPDATE_VIDEO_STATUS, true, 2);
	        
	    } catch (\Google_Service_Exception $e) {
	        $this->parseLimitsExceeded($e);
	        throw $e;
	    } catch (\Google_Exception $e) {
	        var_dump($e->getMessage());
	    }
	}
	
	/**
	 * @deprecated Deprecated in Youtube API in June 2017 and does not seem to 
	 * work anymore.
	 */
	public function updateVideoLocation($videoId, ?float $latitude, ?float $longitude, ?string $locationDescription)
	{
	    if (is_null($latitude) && is_null($longitude) && is_null($locationDescription)) {
	        $this->output->writeln("No location information with which to update video:  ".$videoId);
	        return;
	    }
	    
	    $this->output->writeln("Updating recording location [$latitude] [$longitude] [$locationDescription] for video:  ".$videoId);
	    
	    $youTubeService = $this->yts->getAuthorizedYouTubeDataService();
	    
	    try {
	        
	        $listResponse = $youTubeService->videos->listVideos("recordingDetails", array(
	            'id' => $videoId,
	        ));
	        
	        if (empty($listResponse)) {
	            $this->output->writeln("Unable to find video for updating recording details:  ".$videoId);
	            return;
	        }
	        
	        $video = $listResponse[0];
	        
	        $video['recordingDetails']['location']['latitude'] = $latitude;
	        $video['recordingDetails']['location']['longitude'] = $longitude;
	        $video['recordingDetails']['locationDescription'] = $locationDescription;
	        
	        $videoUpdateResponse = $youTubeService->videos->update("recordingDetails", $video);
	        
	        $this->shareQuery->logServiceCall(self::CALL_ID_UPDATE_VIDEO_RECORDING_DETAILS, true, 2);
	        
	    } catch (\Google_Service_Exception $e) {
	        $this->parseLimitsExceeded($e);
	        throw $e;
	    } catch (\Google_Exception $e) {
	        var_dump($e->getMessage());
	    }
	}
	
	public function uploadVideoLocalizations($videoId)
	{
		if (empty($this->videoLocalizations)) {
			$this->output->writeln("No locazliations with which to update video:  ".$videoId);
		} else {
			$this->output->writeln("Updating locazliations for video:  ".$videoId);
		}
		
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		$googleClient = $youTubeService->getClient();
		
		try {
			
			$listResponse = $youTubeService->videos->listVideos("localizations", array(
					'id' => $videoId,
			));
			
			if (empty($listResponse)) {
				$this->output->writeln("Unable to find video for updating localizations:  ".$videoId);
				return;
			} 
			
			$video = $listResponse[0];
			$videoLocalizations = $video['localizations'];
			
			foreach ($this->videoLocalizations as $key => $value) {
				$videoLocalizations[$key] = $value;
			}
			
			// Put updated localizations back into 'video' and update:
			$video['localizations'] = $this->videoLocalizations;
			$videoUpdateResponse = $youTubeService->videos->update("localizations", $video);
			
			$this->shareQuery->logServiceCall(self::CALL_ID_UPLOAD_VIDEO_LOCALIZATIONS, true, 52);
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
	}
	
	public function uploadVideoThumbnail($videoId, $imagePath)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		$googleClient = $youTubeService->getClient();
		
		try {
			// Specify the size of each chunk of data, in bytes. Set a higher value for
			// reliable connection as fewer chunks lead to faster uploads. Set a lower
			// value for better recovery on less reliable connections.
			$chunkSizeBytes = 1 * 1024 * 1024;
			
			// Setting the defer flag to true tells the client to return a request which can be called
			// with ->execute(); instead of making the API call immediately.
			$googleClient->setDefer(true);
			
			// Create a request for the API's thumbnails.set method to upload the image and associate
			// it with the appropriate video.
			$setRequest = $youTubeService->thumbnails->set($videoId);
			
			// Create a MediaFileUpload object for resumable uploads.
			$media = new \Google_Http_MediaFileUpload(
					$googleClient,
					$setRequest,
					'image/jpeg',
					null,
					true,
					$chunkSizeBytes
					);
			$media->setFileSize(ApiRequestBuilder::getSize($imagePath));
			
			$localFilePath = $this->downloadUrlTmp($imagePath, "thumb_$videoId");
			$status = $this->uploadFileToGoogle($localFilePath, $chunkSizeBytes, $media);
			
			// Delete the temporary local file
			unlink($localFilePath);
			
			// If you want to make other calls after the file upload, set setDefer back to false
			$googleClient->setDefer(false);
			
			$this->shareQuery->logServiceCall(self::CALL_ID_UPLOAD_VIDEO_THUMBNAIL, true, 50);
			
			//$this->output->writeln("THUMBNAIL UPLOAD STATUS:  ".$status['items'][0]['default']['url']);
			return $status;
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
	}

	public function uploadCaptionNoDefer($videoId, $captionFile, $captionLanguage, $captionName)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		try {
			# Insert a video caption.
			# Create a caption snippet with video id, language, name and draft status.
			$captionSnippet = new \Google_Service_YouTube_CaptionSnippet();
			$captionSnippet->setVideoId($videoId);
			$captionSnippet->setLanguage($captionLanguage);
			$captionSnippet->setAudioTrackType('primary');
			$captionSnippet->setIsAutoSynced(false);
			$captionSnippet->setIsDraft(false);
			$captionSnippet->setIsCC(false);

            if (!Utils::isEmpty($captionName)) {
                $captionSnippet->setName($captionName);
            }

			# Create a caption with snippet.
			$caption = new \Google_Service_YouTube_Caption();
			$caption->setSnippet($captionSnippet);

			$localFilePath = $this->downloadUrlTmp($captionFile, "subtitle_".$videoId."_".$captionLanguage);

			$responseCaption = $youTubeService->captions->insert(
				'snippet',
				$caption,
				['sync' => false],
				array(
				  'data' => file_get_contents($localFilePath),
				  'mimeType' => '*/*',
				  'uploadType' => 'multipart'
				)
			  );

			// Delete the temporary local file
			unlink($localFilePath);
			  
			$captionId = $responseCaption->getId();
			$captionSnippet = $responseCaption->getSnippet();
			$captionStatus = sprintf('\t Subtitle name: %s (Id: %s) in %s language, %s status.',
					$captionSnippet['name'], $captionId, $captionSnippet['language'],
					$captionSnippet['status']);
			$this->output->writeln($captionStatus);
			
			$this->shareQuery->logServiceCall(self::CALL_ID_UPLOAD_CAPTION, true, 401);
			return $captionId;
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}

	}
	
	public function uploadCaption($videoId, $captionFile, $captionLanguage, $captionName)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		$googleClient = $youTubeService->getClient();
		
		try {
			# Insert a video caption.
			# Create a caption snippet with video id, language, name and draft status.
			$captionSnippet = new \Google_Service_YouTube_CaptionSnippet();
			$captionSnippet->setVideoId($videoId);
			$captionSnippet->setLanguage($captionLanguage);
			$captionSnippet->setAudioTrackType('primary');
			$captionSnippet->setIsAutoSynced(false);
			$captionSnippet->setIsDraft(false);
			$captionSnippet->setIsCC(false);

            if (!Utils::isEmpty($captionName)) {
                $captionSnippet->setName($captionName);
            }
			
			# Create a caption with snippet.
			$caption = new \Google_Service_YouTube_Caption();
			$caption->setSnippet($captionSnippet);
			
			// Specify the size of each chunk of data, in bytes. Set a higher value for
			// reliable connection as fewer chunks lead to faster uploads. Set a lower
			// value for better recovery on less reliable connections.
			$chunkSizeBytes = 1 * 1024 * 1024;
			
			// Setting the defer flag to true tells the client to return a request which can be called
			// with ->execute(); instead of making the API call immediately.
			$googleClient->setDefer(true);
			
			// Create a request for the API's captions.insert method to create and upload a caption.
			$insertRequest = $youTubeService->captions->insert("snippet", $caption);
			
			// Create a MediaFileUpload object for resumable uploads.
			$media = new \Google_Http_MediaFileUpload(
					$googleClient,
					$insertRequest,
					'*/*',
					null,
					true,
					$chunkSizeBytes
					);
			
			$size = ApiRequestBuilder::getSize($captionFile);
			$media->setFileSize($size);
			
			$localFilePath = $this->downloadUrlTmp($captionFile, "subtitle_".$videoId."_".$captionLanguage);
			$status = $this->uploadFileToGoogle($localFilePath, $chunkSizeBytes, $media);
			
			// Delete the temporary local file
			unlink($localFilePath);
			
			// If you want to make other calls after the file upload, set setDefer back to false
			$googleClient->setDefer(false);
			
			$captionSnippet = $status['snippet'];
			$captionStatus = sprintf('\t Subtitle name: %s (Id: %s) in %s language, %s status.',
					$captionSnippet['name'], $status['id'], $captionSnippet['language'],
					$captionSnippet['status']);
			$this->output->writeln($captionStatus);
			
			$this->shareQuery->logServiceCall(self::CALL_ID_UPLOAD_CAPTION, true, 401);
			return $status['id'];
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
		
	}
	
	// TODO:  If this proves useful, move to Utils
	// This allows us to use a GET presigned URL to 
	// retrieve content length, which we would normally
	// use a HEAD request for...
	private static function retrieveContentLengthViaGet(string $uri) {
	    
	    $rangeBytes = '0-0';
	    
	    $client = new \GuzzleHttp\Client(['headers' => [ 'Range' => 'bytes='.$rangeBytes ]]);
	    $response = $client->get($uri);
	    
	    if ($response->hasHeader("Content-Range")) {
	        
	        $contentRange = $response->getHeader("Content-Range");
	        $contentLength = str_replace('bytes '.$rangeBytes."/", "", $contentRange);
	        
	        if (is_numeric($contentLength)) {
	            return intval($contentLength);
	        } else if (is_array($contentLength) && !Utils::isEmpty($contentLength) && is_numeric($contentLength[0])) {
	            return intval($contentLength[0]);
	        }
	        
	    }
	    
	    return null;
	    
	}
	
	public function downloadUrlTmp($url, $basename, bool $isMasterAssetUrl = false)
	{
		$urlParts = parse_url($url);
		$urlPathParts = pathinfo($urlParts['path']);
		$ext = $urlPathParts['extension'];
		
		$localFilePath = "/tmp/$basename.$ext";
		$transferFilePath= "$localFilePath.inprogress";
		
		$urlForDownload = $url;
		if (true === $isMasterAssetUrl && true === $this->ovpStorageService->isPrivateStorageUrl($url)) {
		    
		    $this->output->writeln("Is private storage URL - obtaining presigned GET URL for download");
		    $urlForDownload = $this->ovpStorageService->getPresignedUrl($url, 10, "GET");
		    
		}
		
		$urlContentLength = self::retrieveContentLengthViaGet($urlForDownload) ?? Utils::retrieveContentLength($urlForDownload);
		
		if (file_exists($localFilePath)) {
			
			$localFileSize = filesize($localFilePath);
			if ($urlContentLength == $localFileSize) {
				$this->output->writeln("File [$localFilePath] already downloaded and size matches content-length of URL...");
				return $localFilePath;
			} else {
				$this->output->writeln("File [$localFilePath] size [$localFileSize] mismatch compared with URL content-length [$urlContentLength] ...");
			}
			
		}
		
		$this->output->writeln("Starting download to file [$localFilePath] from URL [$urlForDownload]");
		$timeStart = microtime(true);
		
		$client = new \GuzzleHttp\Client();
		$client->request('GET', $urlForDownload, [
				'sink' => $transferFilePath,
		]);
		
		$timeEndDownload = microtime(true);
		$duration = $timeEndDownload - $timeStart;
		
		rename($transferFilePath, $localFilePath);
		$this->output->writeln("File [$localFilePath] having content length [$urlContentLength] downloaded in [$duration]");
		
		return $localFilePath;
	}
	
	protected function uploadFileToGoogle($localFilePath, $chunkSizeBytes, \Google_Http_MediaFileUpload &$media)
	{
		$this->output->writeln("Starting upload of file [$localFilePath] to Google of size [".number_format(filesize($localFilePath) / 1048576, 2) ."] MB");
		
		$timeStart = microtime(true);
		$sizeSoFar = 0;
		
		$status = false;
		$handle = fopen($localFilePath, "rb");
		while (!$status && !feof($handle)) {
			$chunk = fread($handle, $chunkSizeBytes);
			$status = $media->nextChunk($chunk);
			
			$sizeSoFar += strlen($chunk);
			$this->output->writeln("\t Uploaded [". number_format($sizeSoFar/ 1048576, 2) . "] MB so far...");
		}
		
		fclose($handle);
		
		$timeEndUpload = microtime(true);
		$duration = $timeEndUpload - $timeStart;
		
		$this->output->writeln("File [$localFilePath] uploaded to Google in [$duration]");
		
		return $status;
	}
	
	public function createPlaylist($title, $description)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		try {
			
			// 1. Create the snippet for the playlist. Set its title and description.
			$playlistSnippet = new \Google_Service_YouTube_PlaylistSnippet();
			$playlistSnippet->setTitle($title);
			$playlistSnippet->setDescription($description);
			
			// 2. Define the playlist's status.
			$playlistStatus = new \Google_Service_YouTube_PlaylistStatus();
			$playlistStatus->setPrivacyStatus($this->videoOrPlaylistStatus);
			
			// 3. Define a playlist resource and associate the snippet and status
			// defined above with that resource.
			$youTubePlaylist = new \Google_Service_YouTube_Playlist();
			$youTubePlaylist->setSnippet($playlistSnippet);
			$youTubePlaylist->setStatus($playlistStatus);
			
			// 4. Call the playlists.insert method to create the playlist. The API
			// response will contain information about the new playlist.
			$playlistResponse = $youTubeService->playlists->insert('snippet,status',
					$youTubePlaylist, array());
			$playlistId = $playlistResponse['id'];
			
			$this->shareQuery->logServiceCall(self::CALL_ID_CREATE_PLAYLIST, true, 54);
						
			return $playlistId;
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
		
	}

	public function createPlaylistItem($videoId, $playlistId, int $position = null)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		try {
			
			// 5. Add a video to the playlist. First, define the resource being added
			// to the playlist by setting its video ID and kind.
			$resourceId = new \Google_Service_YouTube_ResourceId();
			$resourceId->setVideoId($videoId);
			$resourceId->setKind('youtube#video');
			
			// Then define a snippet for the playlist item. Set the playlist item's
			// title if you want to display a different value than the title of the
			// video being added. Add the resource ID and the playlist ID retrieved
			// in step 4 to the snippet as well.
			$playlistItemSnippet = new \Google_Service_YouTube_PlaylistItemSnippet();
			//$playlistItemSnippet->setTitle('First video in the test playlist');
			$playlistItemSnippet->setPlaylistId($playlistId);
			$playlistItemSnippet->setResourceId($resourceId);
			
			if (!is_null($position)) {
			    $playlistItemSnippet->setPosition($position);
			}
			
			// Finally, create a playlistItem resource and add the snippet to the
			// resource, then call the playlistItems.insert method to add the playlist
			// item.
			$playlistItem = new \Google_Service_YouTube_PlaylistItem();
			$playlistItem->setSnippet($playlistItemSnippet);
			$playlistItemResponse = $youTubeService->playlistItems->insert(
					'snippet,contentDetails', $playlistItem, array());
			
			$playlistItemId = $playlistItemResponse['id'];
			
			$this->shareQuery->logServiceCall(self::CALL_ID_CREATE_PLAYLIST_ITEM, true, 54);
			return $playlistItemId;
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
		
	}
	
	public function deleteVideoList($playlistId)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		try {
			
			$youTubeService->playlists->delete($playlistId);
			$this->shareQuery->logServiceCall(self::CALL_ID_DELETE_PLAYLIST, true, 50);
			
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
		
	}
	
	public function deleteCaption($captionId)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		try {
			
			$youTubeService->captions->delete($captionId);
			$this->shareQuery->logServiceCall(self::CALL_ID_DELETE_CAPTION, true, 50);
			
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
		
	}
	
	public function listCaptions($videoId)
	{
		$youTubeService = $this->yts->getAuthorizedYouTubeDataService();
		
		try {
			
			$captions = $youTubeService->captions->listCaptions($videoId, "snippet");
			$this->shareQuery->logServiceCall(self::CALL_ID_LIST_CAPTIONS, true, 50);
			
			return $captions;
			
		} catch (\Google_Service_Exception $e) {
			$this->parseLimitsExceeded($e);
			throw $e;
		} catch (\Google_Exception $e) {
			var_dump($e->getMessage());
		}
		
	}
	
	public function listVideoCategoriesUS()
	{
	    $youTubeService = $this->yts->getAuthorizedYouTubeDataService();
	    
	    try {
	        
	        $videoCategories = $youTubeService->videoCategories->listVideoCategories("snippet", ["regionCode" => "US"]);
	        return $videoCategories;
	        
	    } catch (\Google_Service_Exception $e) {
	        $this->parseLimitsExceeded($e);
	        throw $e;
	    } catch (\Google_Exception $e) {
	        var_dump($e->getMessage());
	    }
	    
	}
	
	public function listGuideCategoriesUS()
	{
	    $youTubeService = $this->yts->getAuthorizedYouTubeDataService();
	    
	    try {
	        
	        $guideCategories = $youTubeService->guideCategories->listGuideCategories("snippet", ["regionCode" => "US"]);
	        return $guideCategories;
	        
	    } catch (\Google_Service_Exception $e) {
	        $this->parseLimitsExceeded($e);
	        throw $e;
	    } catch (\Google_Exception $e) {
	        var_dump($e->getMessage());
	    }
	    
	}
	
	protected function parseLimitsExceeded(\Google_Service_Exception $e) 
	{	
		// 403:  quotaExceeded
		// 400:  uploadLimitExceeded
		$reason = null;
		$code = $e->getCode();
		
		$errors = $e->getErrors();
		if (isset($errors[0]) && isset($errors[0]['reason'])) {
			$reason = $errors[0]['reason'];
		}
		
		if ($code == 403 && $reason == 'quotaExceeded') {
			$this->yts->notifyQuotaExceeded();
			return true;
		} else if ($code == 400 && $reason == 'uploadLimitExceeded') {
			$this->yts->notifyUploadLimitExceeded();
			return true;
		}
		
		return false;
	}
	
}