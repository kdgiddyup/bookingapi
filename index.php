<?php
/**
 * Search and display recent confinement data
 * for Beaufort County and Lexington County detention centers.
 * 
 * Version 2.1 adds logic to handle script trigger via cronjob
 *
 * LICENSE: None.
 *
 * @author      Kelly Davis <kellydavis1974 at gmail dot com> 
 * @version     Release 2.1
 * @since       File available since Release 2.0
 */


// cron job commands would be something like:
//  php -q /var/www/html/bookings.thestate.com/index.php?requested=beaufort
//  php -q /var/www/html/bookings.thestate.com/index.php?requested=lexington

// allow cross origin resource sharing
header("Access-Control-Allow-Origin: *");

// allow script to run for server maximum, for larger record requests
set_time_limit(0);

// use this library to scrape html pages returned in phases of the lexington county process
require_once("simple_html_dom.php");

date_default_timezone_set('America/New_York');

// test and retrieve URL params; default to 0 for start
// and end (ie, current day's bookings)
$requested = isset($_GET["requested"]) ? $_GET["requested"] : "";
$start= isset($_GET["start"]) ? -$_GET["start"] : 0;
$end = isset($_GET["end"]) ? -$_GET["end"] : 0;

$data = array();
$beaufort_file = dirname(__FILE__).'/cache/beaufortcache.txt';
$lex_file = dirname(__FILE__).'/cache/lexcache.txt';
$startTarget = strtotime("-90 days 0:0:0", strtotime('now'));
$endTarget = strtotime("0 days 0:0:0", strtotime('now'));
$userStart = strtotime("$start days 0:0:0", strtotime('now'));
$userEnd = strtotime("$end days 0:0:0", strtotime('now'));
/* debug */
//$test = array();


if ($requested === "all") {
    // this is a call from the cron job
    // we're getting fresh data from all sources 
    // to update our cache files

    // start with Beaufort
    $sources = array(
        0 => array(
            'agency'	=> 'Beaufort County',
            //'url'		=> 'http://mugshots.bcgov.net/booked3.xml'
            'url'		=> 'http://mugshots.bcgov.net/booked90.xml'
        )
    );

    if (!file_exists('cache')){
        mkdir('cache');
    }

    /*  App  */
    // start scraping data from this source
    $i = 0;
    foreach ($sources as $source) {
        $data[$i]['agency'] = $source['agency'];
        $data[$i]['url'] = $source['url'];
        $data[$i]['success'] = true;
        $xml = simplexml_load_file($source['url']);
        $data[$i]['data'] = array();
        $j = 0;
        foreach ($xml->ins->in as $inmate) {
            $booked = date_create_from_format('G:i:s m/d/y', (string) $inmate->bd);
            $timestamp = strtotime($booked->setTime(0, 0, 0)->format('Y-m-d H:i:s'));

            // Only process inmates for the date target window.
            if ($timestamp >= $startTarget && $timestamp <= $endTarget) {

            /* debug */
                //$test[] = $inmate;

                $name_last = (string) $inmate->nl;
                $name_first = (string) $inmate->nf;
                $name_middle = (string) $inmate->nm;

                $data[$i]['data'][$j]['last'] = $name_last;
                $data[$i]['data'][$j]['first'] = $name_first;
                $data[$i]['data'][$j]['middle'] = $name_middle;


                // Address.
                $data[$i]['data'][$j]['city'] = (string) $inmate->csz;

                // Race
                $data[$i]['data'][$j]['race'] = (string) explode(" / ", $inmate->racegen)[0];
            
                // Gender
                $data[$i]['data'][$j]['sex'] = (string) explode(" / ", $inmate->racegen)[1];

                // Date of birth. Needs a little wrangling because of two-digit pre-epoch years;
                // strtotime() is not reliable since it maps values between 0-69 to 2000-2069
                // and values between 70-100 to 1970-2000.
                // Helped here by fact that we also get their age so we can just subtract
                // age from current year to yield birth year
                $dob = explode("/", $inmate->dob);
                $inmate->dob = $dob[0]."/".$dob[1]."/".(date("Y") - $inmate->age);
                $data[$i]['data'][$j]['dob'] = (string) date("M j, Y", strtotime($inmate->dob));
            
                // Age.
                $data[$i]['data'][$j]['age'] = (string) $inmate->age;

                // Height.
                $data[$i]['data'][$j]['height'] = (string) $inmate->ht;

                // Weight.
                $data[$i]['data'][$j]['weight'] = (string) $inmate->wt;

                // Mugshot: make sure it's an actual image file.
                $url_mug = (string) $inmate->image1['src'];
                if (preg_match('/\.(jpeg|jpg|png|gif)$/i', $url_mug)) {
                    $data[$i]['data'][$j]['photo'] = $url_mug;
                }
                $data[$i]['data'][$j]['timestamp'] = $timestamp;
                // arrest info
                // is there any?
                if (array_key_exists("ar", $inmate)) {
                    $data[$i]['data'][$j]['arrestinfo'][]['present'] = (boolean) true;
                    $data[$i]['data'][$j]['arrestinfo'][] = $inmate->ar;
                }
                // Booking number.
                $data[$i]['data'][$j]['booknum'] = (string) $inmate->bn;
            
                // Booking date and time.
                $data[$i]['data'][$j]['booktime'] = (string) date("g:i a, M j, Y", strtotime($inmate->bd));

                // Release date
                $data[$i]['data'][$j]['reldate'] = ($inmate->dtout == "Confined") ? (string) "Confined" : (string) date("g:i a", strtotime($inmate->tmout)).", ".date("M j, Y", strtotime($inmate->dtout));
            
                // Inmate number.
                $data[$i]['data'][$j]['inmatenum'] = (string) $inmate->nn;

                $j++;
            }
        }
        $i++;
    }
    // cache this 90 days' worth of data
    $data[0]['cached'] = false;
    $data_to_cache = json_encode($data[0],true);
    file_put_contents($beaufort_file,$data_to_cache);


    // Now let's do Lexington

    $sources = array(
        0 => array(
            'agency'	=> 'Lexington County Sheriff\'s Department',
            'main'      => 'http://jail.lexingtonsheriff.net/jailinmates.aspx',
            'list'		=> 'http://jail.lexingtonsheriff.net/jqHandler.ashx?op=s',
            'detail'    => 'http://jail.lexingtonsheriff.net/InmateDetail.aspx',
            'mug'       => 'http://jail.lexingtonsheriff.net/Mug.aspx',
            'cookie'    => dirname(__FILE__).'/tmp/lexmugs.txt'
        )
    );
    if (!file_exists('tmp'))
        mkdir('tmp');

    /*  App  */
    $i = 0;
    foreach ( $sources as $source ) {
        /* curl init */
        /* debug */
        // logging headers
        //$curlLog = fopen('./logs/curlLog.txt','w');
        
        /* remove any existing cookie */  
        {
            if ( file_exists($source['cookie']) ) {
                unlink($source['cookie']);
            }
                
        } 

        /* GET homepage to retrieve session and form state values */
        $ch = curl_init($source['main']);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_REFERER => $source['main'],
            CURLOPT_COOKIEJAR => $source['cookie'],
            CURLOPT_COOKIEFILE => $source['cookie'],
        ));

        $home = curl_exec($ch);
        curl_close($ch);

        if (!$home)
            $data[$i]->scrapeError = curl_error($ch);

        // POST to data handler to retrieve initial list of detainees
        $chList = curl_init($source['list']);
        curl_setopt_array( $chList, array(
            CURLOPT_REFERER => $source['main'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_COOKIEJAR => $source['cookie'],
            CURLOPT_COOKIEFILE => $source['cookie'],
            CURLOPT_POSTFIELDS => 't=ii&_search=false&page=1&rows=10000&sidx=date_arr&sord=desc&nd=1525363643699',

            /* debug options  */
            // CURLOPT_VERBOSE => true,
            // CURLOPT_STDERR => $curlLog, 
            // CURLOPT_ENCODING => "",
            )
            );
        $list = curl_exec($chList);
        if (!$list) 
            $data[$i]->listError = curl_error($chList);
        curl_close($chList); 
        $list = json_decode($list);

        /* for debug */
        //$headerSent = curl_getinfo($ch, CURLINFO_HEADER_OUT);

        // suppress error output for html loading
        libxml_use_internal_errors(true);
        
        // $postHome will hold our post variables for curl calls
        $inputsHome = array();
        $postHome = array();
        $dom = new DOMDocument();
        $dom->loadHTML($home);
        $inputs = $dom->getElementsByTagName('input');
        foreach ( $inputs as $input ) 
            $postHome[$input->getAttribute('name')] = $input->getAttribute('value');   
        
        $data[$i]['agency'] = $source['agency'];
        $data[$i]['url'] = $source['main'];
        $data[$i]['success'] = true;
        
        /* debug */
        // $data[$i]['cookie'] = $source['cookie'];
        
        $data[$i]['data'] = array();
        $j = 0;
        foreach ( $list->rows as $inmate )
        {
            $booked = strtotime($inmate->disp_arrest_date, strtotime('now'));
            //$timestamp = strtotime( $booked->setTime(0,0,0)->format('Y-m-d H:i:s') );

            // Only process inmates for the date target window.
            if ($booked >= $startTarget && $booked <= $endTarget) {
                // attempt to get mug

                /* for debug - write headers for mug request */
                // $mugLog = fopen('./logs/mugLog.txt','w');
                // $detailLog = fopen('./logs/detailLog.txt','w');
                
                // update inmate number in hidden form element and process $post array to string
                $postHome['ctl00$MasterPage$mainContent$CenterColumnContent$hfRecordIndex'] = $inmate->my_num;
                
                $temp_string = array();
                foreach ($postHome as $key => $value) {
                    $temp_string[] = $key . "=" . urlencode($value);
                }
                // Bring in array elements into string
                $postHomeString = implode('&', $temp_string);
                

                // POST to get inmate detail;
                // event validator, event state and event generator are passed in the $post_string
                $chDet = curl_init($source['main']);
                curl_setopt_array($chDet, array(
                        CURLOPT_REFERER => $source['main'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPGET => true,
                        CURLOPT_COOKIEJAR => $source['cookie'],
                        CURLOPT_COOKIEFILE => $source['cookie'],
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_FOLLOWLOCATION => true,     // Follow redirects
                        CURLOPT_MAXREDIRS => 4,
                        CURLOPT_POSTFIELDS => $postHomeString,
                        CURLOPT_HTTPHEADER => array('Expect:  '),
                        //CURLOPT_HEADER => true,
                        /* for debug - more info */
                        //CURLINFO_HEADER_OUT => true,
                        // CURLOPT_VERBOSE => true,
                        // CURLOPT_STDERR => $detailLog
                    ));
                    
                    
                $detail = curl_exec($chDet);
                if (!$detail) {
                    $inmate->detailError = curl_error($chDet);
                }

                $redirectUrl = curl_getinfo($chDet)['url'];
                
                // debug - export headers to inmate object for examination in console
                // $inmate->curlInfo = curl_getInfo($chdet);
                curl_close($chDet);
                
                // let's take a look at the returned HTML
                // but only if it's not an empty string
                if ( !empty($detail)) {
                    $detailDom = new DOMDocument();
                    $detailDom->loadHTML($detail);
                
                    // scrape some arrest details not available in the main list
                    $detailDom2 = new simple_html_dom();
                    $detailDom2->load($detail);
                    
                    $relDate = trim($detailDom2->find("#mainContent_CenterColumnContent_lblReleaseDate", 0)->plaintext);
                    $inmate->relDate = $relDate ? $relDate : "Not listed";

                    $courtNext = trim($detailDom2->find("#mainContent_CenterColumnContent_lblNextCourtDate", 0)->plaintext);
                    $inmate->courtNext = $courtNext ? $courtNext : "Not listed";
                    
                    $totalBond = trim($detailDom2->find("#mainContent_CenterColumnContent_lblTotalBoundAmount", 0)->plaintext);
                    $inmate->totalBond = $totalBond ? $totalBond : "Not listed";
                    
                    $r=0; // row index
                    foreach ($detailDom2->find("#mainContent_CenterColumnContent_dgMainResults tr") as $rows) {
                        if ($r === 0) { // header row
                            $headers = $rows->find("td");
                        } else {  // data rows
                            $item = new stdClass();
                            $c=0; // column index to match headers
                            foreach ($rows->find("td") as $datum) {
                                $label = trim($headers[$c]->plaintext);
                                $item->$label = trim($datum->plaintext);
                                $c++; // increment column
                            }
                            $inmate->charges[] = $item;
                        }
                        $r++; // increment row index
                    };
                                
                    // clean up memory
                    $detailDom2->clear();
                    unset($detailDom2);
                        
                    // store detail event state, validation and generator strings from this document
                    $postDetail = array();

                    $inputs = $detailDom->getElementsByTagName('input');
                    foreach ($inputs as $input) {
                        $postDetail[$input->getAttribute('name')] = $inputValue = $input->getAttribute('value');
                    }

                    // update inmate number in hidden form element and process $post array to string
                    $postDetail['ctl00$MasterPage$mainContent$CenterColumnContent$hfRecordIndex'] = $inmate->my_num;
                    $temp_string = array();
                    foreach ($postDetail as $key => $value) {
                        $temp_string[] = $key . "=" . urlencode($value);
                    }
                    // Bring in array elements into string
                    $postDetailString = implode('&', $temp_string);
                    
                    // clean up
                    unset($detailDom);

                    /* debug: output some curl strings for this inmate */
                    // $inmate->mugQuery = $postDetail;
                    // $inmate->detailDom = $detail;
                    // $inmate->redirect = $redirectUrl;
                    
                    // make call to mug endpoint with new redirect URL set as referer
                    $chMug = curl_init($source['mug']);
                    curl_setopt_array($chMug, array(
                        CURLOPT_REFERER => $redirectUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPGET => true,
                        CURLOPT_COOKIEJAR => $source['cookie'],
                        CURLOPT_COOKIEFILE => $source['cookie'],
                        CURLOPT_FOLLOWLOCATION => true,     // Follow redirects
                        CURLOPT_MAXREDIRS => 4,
                        CURLOPT_POSTFIELDS => $postDetailString,
                        CURLOPT_HTTPHEADER => array('Expect: '),
                        
                        /* for debug - more info */
                        //CURLINFO_HEADER_OUT => true,
                        // CURLOPT_HEADER => false,
                        // CURLOPT_VERBOSE => true,
                        // CURLOPT_STDERR => $mugLog
                    ));

                    $raw_mug = curl_exec($chMug);
                    if (!$raw_mug) {
                        $inmate->mugError = curl_error($chMug);
                        $inmate->image = "http://media.islandpacket.com/static/news/crime/mugshots/noPhoto.jpg";
                    }
                    else {
                        // make the file instead of sending it
                        // $f = fopen('./mugs/mug'.$inmate->my_num.'.jpg', 'wb');
                        // fwrite($f, $raw_mug);
                        // fclose($f);

                        // process image string
                        $mug = imagecreatefromstring($raw_mug);
                        unset($raw_mug);

                        if (!$mug) {
                            $img_data = "http://media.islandpacket.com/static/news/crime/mugshots/noPhoto.jpg";
                        } 
                        else {
                            // start buffering and catch image output
                            ob_start();
                            imagejpeg($mug);
                            $contents =  ob_get_contents();
                            $img_data = "data:image/jpg;base64,".base64_encode($contents);
                            ob_end_clean();
                            imagedestroy($mug);
                            } 
                        $inmate->image = $img_data;
                        unset($img_data);
                        }         
                        curl_close($chMug);
                        unset($chMug);
                        
                        /* remove superfluous to reduce processing
                        load on client side */
                        $inmate->dob = explode(" ", $inmate->dob)[0];
                    /* debug */
                    // $inmate->cookie = $source['cookie'];
                    //$test[] = $inmate;
                    
                    $data[$i]['data'][] = $inmate;
                }
            }
        }
        $i++;
    };
    // cache this 90 days' worth of data
    $data_to_cache = json_encode($data[0],false);
    file_put_contents($lex_file,$data_to_cache);
}
else if ($requested === "beaufort") {
    // this is a call from a front-end, 
    // so fetch cached data
    
    if (!file_exists($beaufort_file)) {
         $data = new stdClass();
         $data->success = false;
         $data->message = "No data is available.";
     }
    else {
        $data = json_decode(file_get_contents($beaufort_file));
        // read cache

        $data->cached = true;
        // flag this as cached data
        
        // extract the data requested by the user
        $inmateData = $data->data;
        $data->data = Array();
        foreach ($inmateData as $inmate){
            if (property_exists($inmate,'timestamp')) {
                if ($inmate->timestamp >= $userStart && $inmate->timestamp <= $userEnd) {
                    $data->data[] = $inmate;
                };
            };
        };
    }      
        header('Content-Type: application/json');
        echo json_encode( $data );
        /* debug */
        //echo json_encode( $test );
}
else if ($requested === "lexington") {
    // this is a call from a front-end,
    if (!file_exists($lex_file)) {
        $data = new stdClass();
        $data->success = false;
        $data->message = "No data is available.";
    }
    else {
        $data = json_decode(file_get_contents($lex_file));
        // read cache

        $data->cached = true;
        // flag this as sourced from cache

        // extract only the data needed by the user
        $inmateData = $data->data;
        $data->data = Array();
        foreach( $inmateData as $inmate){
            if ( strtotime($inmate->{'disp_arrest_date'}) >= $userStart && strtotime($inmate->{'disp_arrest_date'}) <= $userEnd) {
                $data->data[] = $inmate;
            };
        };
    }
    // Return data in JSON format.
    header('Content-Type: application/json');
    echo json_encode( $data );    
}

else {
    $data = Array();
    $data['success'] = false;
    $data['data'] = "No valid agency was requested.";
    header('Content-Type: application/json');
    echo json_encode( $data); 
};
/* debug */
//echo json_encode( $test );