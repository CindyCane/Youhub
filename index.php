<?php
/**
 * Library Requirements
 *
 * 1. Install composer (https://getcomposer.org) CHECK!
 * 2. On the command line, change to this directory (api-samples/php) CHECK!
 * 3. Require the google/apiclient library CHECK!
 *    $ composer require google/apiclient:~2.0
 */

// <--Begin Auth-->
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    throw new \Exception('please run "composer require google/apiclient:~2.0" in "' . __DIR__ . '"');
}

require_once __DIR__ . '/vendor/autoload.php';
session_start();

/*
 * You can acquire an OAuth 2.0 client ID and client secret from the
 * {{ Google Cloud Console }} <{{ https://cloud.google.com/console }}>
 * For more information about using OAuth 2.0 to access Google APIs, please see:
 * <https://developers.google.com/youtube/v3/guides/authentication>
 * Please ensure that you have enabled the YouTube Data API for your project.
 */

// place the "OAUTH2_CLIENT_ID" and "$OAUTH2_CLIENT_SECRET" if you wish to use your own account
$OAUTH2_CLIENT_ID     = '1061973519961-ftrjjmsis3h36qvv45ej2ajepbek7oef.apps.googleusercontent.com';
$OAUTH2_CLIENT_SECRET = 'BEBp4zpo0Bauju2mv_6TpyIQ';

$client = new Google_Client();
$client->setClientId($OAUTH2_CLIENT_ID);
$client->setClientSecret($OAUTH2_CLIENT_SECRET);
$client->setScopes('https://www.googleapis.com/auth/youtube');
$redirect = filter_var('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'], FILTER_SANITIZE_URL);
$client->setRedirectUri($redirect);

// Define an object that will be used to make all API requests.
$youtube = new Google_Service_YouTube($client);

// Check if an auth token exists for the required scopes
$tokenSessionKey = 'token-' . $client->prepareScopes();
if (isset($_GET['code'])) {
    if (strval($_SESSION['state']) !== strval($_GET['state'])) {
        die('The session state did not match.');
    }
    
    $client->authenticate($_GET['code']);
    $_SESSION[$tokenSessionKey] = $client->getAccessToken();
    header('Location: ' . $redirect);
}

if (isset($_SESSION[$tokenSessionKey])) {
    $client->setAccessToken($_SESSION[$tokenSessionKey]);
}
// <--End of Auth-->






// Check to ensure that the access token was successfully acquired.
if ($client->getAccessToken()) {
    // run features
    // upload video feature
    if ($_POST['initialize'] == "initialize") {
        $videoUpload = '';
        try {
            // get file from upload
            $videoPath = $_FILES["UploadFileName"]["tmp_name"];
            
            // get title, description, tags, category privacy and the video file itself
            $snippet = new Google_Service_YouTube_VideoSnippet();
            $snippet->setTitle($_POST['videoTitle']);
            $snippet->setDescription($_POST['videoDesc']);
            // convert string to array while get rid of commas
            $rawTags     = $_POST['videoTags'];
            $refinedTags = preg_split("(e.g., |, |Tags )", $rawTags);
            $snippet->setTags($refinedTags);
            $snippet->setCategoryId($_POST['category']);
            $status                = new Google_Service_YouTube_VideoStatus();
            $status->privacyStatus = $_POST['privacy'];
            
            // Associate the snippet and status objects with a new video resource.
            $video = new Google_Service_YouTube_Video();
            $video->setSnippet($snippet);
            $video->setStatus($status);
            
            // Specify the size of each chunk of data, in bytes. Set a higher value for
            // reliable connection as fewer chunks lead to faster uploads. Set a lower
            // value for better recovery on less reliable connections.
            $chunkSizeBytes = 1 * 1024 * 1024;
            
            // Setting the defer flag to true tells the client to return a request which can be called
            // with ->execute(); instead of making the API call immediately.
            $client->setDefer(true);
            
            // Create a request for the API's videos.insert method to create and upload the video.
            $insertRequest = $youtube->videos->insert("status,snippet", $video);
            
            // Create a MediaFileUpload object for resumable uploads.
            $media = new Google_Http_MediaFileUpload($client, $insertRequest, 'video/*', null, true, $chunkSizeBytes);
            $media->setFileSize(filesize($videoPath));
            
            
            // Read the media file and upload it chunk by chunk.
            $status = false;
            $handle = fopen($videoPath, "rb");
            while (!$status && !feof($handle)) {
                $chunk  = fread($handle, $chunkSizeBytes);
                $status = $media->nextChunk($chunk);
            }
            
            fclose($handle);
            $client->setDefer(false);
            
            
        }
        catch (Google_Service_Exception $e) {
            $videoUpload .= sprintf('<p id="error">A service error occurred: <code>%s</code></p>', htmlspecialchars($e->getMessage()));
        }
        catch (Google_Exception $e) {
            $videoUpload .= sprintf('<p id="error">An client error occurred: <code>%s</code></p>', htmlspecialchars($e->getMessage()));
        }
        
        $_SESSION[$tokenSessionKey] = $client->getAccessToken();
    } else {
        //do nothing
    }
    ;
    
    // get channel card
    getChannelLocalization($youtube, $channelCard);
    $_SESSION[$tokenSessionKey] = $client->getAccessToken();
    
    
    // retrieve upload feature
    try {
        // Call the channels.list method to retrieve information about the
        // currently authenticated user's channel.
        $channelsResponse = $youtube->channels->listChannels('contentDetails', array(
            'mine' => 'true'
        ));
        
        $videoName      = '';
        $videoThumbnail = '';
        foreach ($channelsResponse['items'] as $channel) {
            // call the playlistItems.list method to get list of videos
            $uploadsListId = $channel['contentDetails']['relatedPlaylists']['uploads'];
            
            $playlistItemsResponse = $youtube->playlistItems->listPlaylistItems('snippet', array(
                'playlistId' => $uploadsListId,
                'maxResults' => 50
            ));
            
            foreach ($playlistItemsResponse['items'] as $playlistItem) {
                
                //transfer the infomation to the document
                $videoThumbnail .= sprintf('<a href="https://www.youtube.com/watch?v=%s"><img id="thumbnail" src="%s" alt="Video thumbnail"></a>', $playlistItem['snippet']['resourceId']['videoId'], $playlistItem["snippet"]["thumbnails"]["high"]["url"]);
                $videoName .= sprintf('<a href="https://www.youtube.com/watch?v=%s" style="text-decoration: none"><h3 id="videoName">%s</h3></a>', $playlistItem['snippet']['resourceId']['videoId'], $playlistItem['snippet']['title']);
                
                // get video stats
                $videoId = $playlistItem['snippet']['resourceId']['videoId'];
                getVideoLocalization($youtube, $videoId, $videoStats);
                
                
            }
            
        }
    }
    catch (Google_Service_Exception $e) {
        $videoName = sprintf('<p>A service error occurred: <code>%s</code></p>', htmlspecialchars($e->getMessage()));
    }
    catch (Google_Exception $e) {
        $videoName = sprintf('<p>An client error occurred: <code>%s</code></p>', htmlspecialchars($e->getMessage()));
    }
    
    
    
} else {
    // If the user hasn't authorized the app, initiate the OAuth flow
    $state = mt_rand();
    $client->setState($state);
    $_SESSION['state'] = $state;
    
    $authUrl    = $client->createAuthUrl();
    $pleaseAuth = <<<END
 <section id="loginSection">
  <img id="logo" alt="Logo" src="img/logo.png">
  <h1 id="mainTitle">A site for managing your Youtube Channel</h1>
  <a href="$authUrl"><button id="loginButton">Login</button></a>
  </section>
END;
}

// <--Begin channel card function-->
function getChannelLocalization(Google_Service_YouTube $youtube, &$channelCard)
{
    // Call the YouTube Data API's channels.list method to retrieve your channel
    $channels = $youtube->channels->listChannels("snippet, statistics", array(
        'mine' => true
    ));
    
    
    $myName  = $channels[0]["snippet"];
    $myPic   = $channels[0]["snippet"]["thumbnails"]['high'];
    $myStats = $channels[0]["statistics"];
    
    $channelCard .= sprintf('<div id="myPicWrapper"><img src="%s" id="myPic" alt="My Channel Picture"></div>', $myPic['url']);
    $channelCard .= sprintf('<h2 id="channelName">%s</h2>', $myName['title']);
    $channelCard .= sprintf('<h2 id="channelDesc">%s</h2>', $myName['description']);
    $channelCard .= sprintf('<h2 id="views">Views: %s</h2>', $myStats['viewCount']);
    $channelCard .= sprintf('<h2 id="subs">Subscribers: %s</h2>', $myStats['subscriberCount']);
}
// <--End channel card function-->

// <--Begin video stats function-->
function getVideoLocalization(Google_Service_YouTube $youtube, $videoId, &$videoStats)
{
    // Call the YouTube Data API's videos.list method to retrieve videos.
    $videos = $youtube->videos->listVideos("statistics, snippet", array(
        'id' => $videoId
    ));
    
    // transfer the infomation to the document
    $views = $videos[0]["statistics"];
    
    $videoStats .= sprintf('<div id="videoStats"><h3><img class="icon" alt="View" src="img/view.png">%s</h3>', $views['viewCount']);
    $videoStats .= sprintf('<h3><img class="icon" alt="Like" src="img/like.png">%s</h3>', $views['likeCount']);
    $videoStats .= sprintf('<h3><img class="icon" alt="Dislike" src="img/dislike.png">%s</h3></div>', $views['dislikeCount']);
    
}
// <--End video stats function-->

?>

<!doctype html>
<html>
<head>
<title>Set and retrieve localized metadata for a channel</title>
<link href="https://fonts.googleapis.com/css?family=Open+Sans" rel="stylesheet">
<link rel=stylesheet href="css/main.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
</head>
<body>
    <section id="auth">
        <?= $pleaseAuth ?>
   </section>
        <section id="videoUploadWrapper">
        <div id="videoUpload">
            <?= $videoUpload ?>
           <button id="closeBox"><img id="close" src="img/close.png"></button>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input class="noShow" type="text" name="initialize" id="initialize">
                <div id="textBoxes">
                    <input type="text" name="videoTitle" placeholder="Title" id="inputTitle">
                    <br>
                    <textarea name="videoDesc" placeholder="Descriptions" id="inputDesc"></textarea>
                    <br>
                    <input type="text" name="videoTags" placeholder="Tags (e.g., tie a noose, commit suicide, knife)" id="inputTags">
                </div>
                <div id="options">
                    <select name="privacy" id="privacy">
                        <option value="public">Public</option>
                        <option value="private">Private</option>
                        <option value="unlisted">Unlisted</option>
                    </select>
                    <br>
                    <select name="category" id="category">
                        <option value="1">Film &amp; Animation</option>
                        <option value="2">Autos &amp; Vehicles</option>
                        <option value="10">Music</option>
                        <option value="15">Pets &amp; Animals</option>
                        <option value="17">Sports</option>
                        <option value="19">Travel &amp; Events</option>
                        <option value="20">Gaming</option>
                        <option value="22">People &amp; Blogs</option>
                        <option value="23">Comedy</option>
                        <option value="24">Entertainment</option>
                        <option value="25">News &amp; Politics</option>
                        <option value="26">Howto &amp; Style</option>
                        <option value="27">Education</option>
                        <option value="28">Science &amp; Technology</option>
                        <option value="29">Nonprofits &amp; Activism</option>
                    </select>
                    <p id="filesToUpload">File to upload : <input id="file" type ="file" name = "UploadFileName"></p>
                    <input type = "submit" name = "Submit" id="submit" value = "Upload Video">
                    <h3 id="wait">Please Wait...</h3>
                </div>
            </form>
        </div>
    </section>
    <section id="everythingElse">
        <section id="channelCard">
            <h2 id="myChannel">My Channel</h2>
            <?= $channelCard ?>
       </section>
        <section id="videoSection">
            <h2 id="myVideos">My Videos</h2>
            <section id="videoInfo">
                <section id="videoThumbnailWrapper">
                    <?= $videoThumbnail ?>
               </section>
                <section id="videoNameWrapper">
                    <?= $videoName ?>
               </section>
                <section id="videoStatsWrapper">
                    <?= $videoStats ?>
               </section>
            </section>
            <div id="uploadVideoWrapper">
                <button id="uploadVideo">Upload Video</button>
            </div>
        </section>
    </section>
    <script>
        // login screen
        $(document).ready ( function(){
           if ($('#loginSection').length > 0) {
                $("#everythingElse").css("display", "none");
            } else {
                $("#auth").css("display", "none");
                $("#everythingElse").css("display", "");
            };
        });
        // if there is an error, get rid of the form
        $(document).ready ( function(){
           if ($('#error').length > 0) {
                $("#uploadForm").css("display", "none");
            } else {
            };
        });
        // show the upload box while initializes the video upload process
        $( "#uploadVideo" ).click(function() {
          $("#initialize").val('initialize');
          $("#videoUploadWrapper").fadeIn("fast");
        });
        // close box
        $( "#closeBox" ).click(function() {
          $("#videoUploadWrapper").fadeOut("fast");
        });
        // get rid of the submit button after click to prevent multiple uploads through rapid clicks
        $( "#submit" ).click(function() {
          $("#submit").css("visibility", "hidden");
          $("#wait").delay( 500 ).fadeIn("slow");
        });
    </script>
</body>
</html>