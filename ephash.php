<?php

class Hashimage {

	var $settings;

    function __construct($args = array())
    {
        // Setup settings array
        $this->settings = array(
            'hashtag'               => $_GET['hash']?$_GET['hash']:'unicorn',
            'limit'                 => $_GET['limit']?$_GET['limit']:'5',
            'social'                => $_GET['social']?$_GET['social']:'twitter',
            'networks'				=> array(
					            'instagram' => 'instagr.am',
					            'twitpic'   => 'twitpic',
					            'twitter'   => 'pic.twitter.com',
					            'yfrog'     => 'yfrog',
					            'flickr'    => 'flic.kr',
					            'plixi'     => 'plixi'
					        ),
            'instagram_client_id'   => INSTAGRAM_CLIENT_ID,
        );
		
        $this->twitterUrl = '?q='.str_replace('#','', urldecode($this->settings['hashtag']) ).'&result_type=mixed&count=50';
        
        // Instagram API with hashtag and client_id
        $this->instagramUrl = 'https://api.instagram.com/v1/tags/'.str_replace('#','',$this->settings['hashtag']).'/media/recent?client_id='.$this->settings['instagram_client_id'];

        if( $_GET['link']) {
        	$this->twitterUrl = urldecode($_GET['link']);
        	$this->instagramUrl  = $_GET['link'];
        }

        // Do the magic
        $this->_init();
    }

    /**
    * The heart of the plugin, here we do the heavy loading
    **/
    private function _init()
    {
        $twitterjson    = '';
        $instagramjson  = '';
        $image          = array();

        $json = array();
        if ($this->settings['social'] == "twitter") {

 
            $settings = array(
                'oauth_access_token'        => OAUTH_ACCESS_TOKEN,
                'oauth_access_token_secret' => OAUTH_ACCESS_TOKEN_SECRET,
                'consumer_key'              => CONSUMER_KEY,
                'consumer_secret'           => CONSUMER_SECRET
            );
            $url = 'https://api.twitter.com/1.1/search/tweets.json';
            $requestMethod = 'GET';
            $twitter = new TwitterAPIExchange($settings);

            $twitterjson = json_decode($twitter->setGetfield($this->twitterUrl)
             ->buildOauth($url, $requestMethod)
             ->performRequest());

// echo '<pre>';
// $json['tweetUrl'] =  $this->twitterUrl;
// print_r( $twitterjson );
// echo '</pre>';
            if (isset($twitterjson) && $twitterjson->statuses) {

                if( $twitterjson->search_metadata->next_results ) {
                	$json['next'] = $twitterjson->search_metadata->next_results;
                } else {
                    $json['next'] = $twitterjson->search_metadata->refresh_url;
                }

                // echo $json['next'];
                foreach ($twitterjson->statuses as $results) {

                    // If it is links to other networks
                    if (isset($results->entities) && isset($results->entities->urls)) {
                        foreach ($results->entities->urls as $url) {
                            if (!empty($url->expanded_url) && !empty($url->url)) {
                                $links[] = array(
                                	'img' 		=> $url->expanded_url,
                                	'source'	=> $url->url,
                                	'text'		=> $results->text,
                                	'time'		=> $results->created_at,
                                	'id'		=> $results->id_str,
                                	);
                            }
                        }
                    }

                    // If it is twitter media
                    if (isset($results->entities) && isset($results->entities->media)) {
                        foreach ($results->entities->media as $image) {
                            if (!empty($image->media_url) && !empty($image->url)) {
                            	 $images[] = array(
                                	'img' 		=> $image->media_url,
                                	'source'	=> $image->url,
                                	'text'		=> $results->text,
                                	'time'		=> $results->created_at,
                                	'id'		=> $results->id_str,
                                	);
                            }
                        }
                    }
                }

                // Get the images from the links on twitter
                if ($links && $images) {
                    $images = array_merge($this->_extractimages($links),$images);
                } else if (!$images) {
                    $image = $links;
                }
            }
        } else if ($this->settings['social'] == "instagram") {
        	
            $instagramjson = json_decode($this->_fetchurl($this->instagramUrl, 600+rand(1,120)));
            

            if (isset($instagramjson) && isset($instagramjson->data)) {

            	$json['next'] = $instagramjson->pagination->next_url;

                foreach ($instagramjson->data as $result) {
                    if (!empty($result->link) && !empty($result->images->standard_resolution->url)) {

                    	$images[] = array (
                    		'img' 		=> $result->images->standard_resolution->url,
                    		'source'	=> $result->link,
                    		'text'		=> $result->caption->text,
                    		'time'		=> $result->created_time,
                            'post_url'  => $result->link,
                    		'id'		=> $result->id,
                    		);

                    }
                }
            }
        }

        $json['images'] = $images;

        echo json_encode($json);
        
    }

    /**
    * Fetch the url
    **/
    private function _fetchurl($url = null, $ttl = 86400){
        if ($url) {
            
            // Chec if cache of the urls allready exists, if not, get content of the url
            // if (false === ($data = get_site_transient($option_name))) {
                $ch = curl_init();
                $options = array(
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 10
                );
                curl_setopt_array($ch, $options);
                $data['chunk'] = curl_exec($ch);
              
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                // if($http_code === 200){
                //     // Set the new cache
                //     // set_site_transient($option_name, $data, $ttl);
                // }
            // }


            return $data['chunk'];
        }
    }

    /**
    * Extract the images from the data returned
    **/
    private function _extractimages($links){
        if($links){
            foreach($links as $link){
                // yfrog.com

            	$img = "";

                if (stristr($link['img'],'yfrog.com'))
                {
                    $img       = $this->_extractyfrog($link['img']);
                }
                // plixi.com
                else if (stristr($link['img'],'plixi.com'))
                {
                    $img       = $this->_extractplixi($link['img']);
                }
                // instagr.am
                else if (stristr($link['img'],'instagr.am'))
                {
                    $img       = $this->_extractinstagram($link['img']);
                }
                // twitpic.com
                else if (stristr($link['img'],'twitpic.com'))
                {
                    $img       = $this->_extracttwitpic($link['img']);
                }
                // flic.kr
                else if (stristr($link['img'],'flic.kr'))
                {
                    $img       = $this->_extractflickr($link['img']);
                }

                $images[] = array(
                	'img'		=> $img,
                	'source'	=> $link['source'],
                	'text'		=> $link['text'],
                	'time'		=> $link['time'],
                	'id'		=> $link['id'],
                	);

            }

            return $images;
        }
    }

    /**
    * Extract yfrog images
    **/
    private function _extractyfrog($link){
        return trim($link,'”."').':iphone';
    }

    /**
    * Extract twitpic images
    **/
    private function _extracttwitpic($link){
        $linkparts = explode('/',$link);
        return 'http://twitpic.com/show/large/'.$linkparts[3];
    }

    /**
    * Extract flickr images
    **/
    private function _extractflickr($link){
        $string = $this->_fetchurl($link);
        if(isset($string)){
            preg_match_all('!<img src="(.*?)" alt="photo" !', $string, $matches);
            if(isset($matches[1][0])){
                return $matches[1][0];
            }
        }
    }

    /**
    * Extract instagram images
    **/
    private function _extractinstagram($link){
        $link = trim($link);

        $search = 'instagr.am';
        $replace = 'instagram.com';

        $link = str_replace($search, $replace, $link);

        $string = $this->_fetchurl($link);
        if(isset($string)){
            preg_match_all('! class="photo" src="(.*?)" !', $string, $matches);
            if(isset($matches[1][0]) && !empty($matches[1][0])){
                return $matches[1][0];
            }
        }
    }

    /**
    * Extract plixi images
    **/
    private function _extractplixi($link){
        $string = $this->_fetchurl($link);
        if(isset($string)){
            preg_match_all('! src="(.*)" id="photo"!', $string, $matches);
            if($matches[1][0]){
                return $matches[1][0];
            }
        }
    }
}
