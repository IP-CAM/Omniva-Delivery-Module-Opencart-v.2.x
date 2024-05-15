<?php
/*
 * Class for automatic updates
 * public access
 * 
 */
class ControllerModuleOmnivalt extends Controller {

    public function index() {
        $secret = $this->config->get('omnivalt_user');
        if (!$secret || !isset($this->request->get['s']) || $secret !== $this->request->get['s']) {
            return false;
        }

        $result = $this->fetchUpdates();
        return $result;
    }
    public function fetchUpdates() {
        $result = $this->fetchUpdatesFromApiLib(); // Use Omniva API library
        return $result['msg'];

        $this->csvTerminal();
        $terminals = array();
        $csv = $this->fetchURL('https://www.omniva.ee/locations.csv');
        if ( empty($csv) ) return 'Omniva terminal update error: Empty CSV';
        $countries = array();
        $countries['LT'] = 1;
        $countries['LV'] = 2;
        $countries['EE'] = 3;
        $cabins = $this->parseCSV($csv,$countries);
        if ($cabins) {
            $terminals = $cabins;
            $fp = fopen(DIR_DOWNLOAD."omniva_terminals.json", "w");
            fwrite($fp, json_encode($terminals));
            fclose($fp);
            echo "Omniva terminals updated";
            return 'Omniva terminals updated';
        }
        echo "Omniva terminals not updated";
        return 'Omniva terminals not updated';
    }
    
    public function csvTerminal()
    {

        $url = 'https://www.omniva.ee/locations.json';
        $fp = fopen(DIR_DOWNLOAD . "locations.json", "w");
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_FILE, $fp);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($curl);
        curl_close($curl);
        fclose($fp);
    }
        
    private function fetchURL($url) {
        
        $ch = curl_init(trim($url)) or die('cant create curl');
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $out = curl_exec($ch) or die(curl_error($ch));
        if ( curl_getinfo($ch,CURLINFO_HTTP_CODE) != 200 ) die('cannot fetch update from '.curl_getinfo($ch,CURLINFO_EFFECTIVE_URL).': '.curl_getinfo($ch,CURLINFO_HTTP_CODE));

        curl_close($ch);
        return $out;
    }
        
    private function parseCSV($csv, $countries = array()) {
        
        $cabins = array();
        if (empty($csv)) return $cabins;

        if ( mb_detect_encoding($csv,'UTF-8, ISO-8859-1') == 'ISO-8859-1' ) $csv = utf8_encode($csv);
        $rows = str_getcsv($csv, "\n"); #parse the rows, remove first 
        $newformat = count(str_getcsv($rows[0], ';')) > 10 ? 1 : 0;
        array_shift($rows);

        foreach($rows as $row) {
            $cabin = str_getcsv($row, ';');
            # there are lines with all fields empty in estonian file, workaround
            if ( count(array_filter($cabin)) ) { 
                if ($newformat) {
                    if ( !empty($countries[strtoupper(trim($cabin[3]))]) ) {
                        # closed ? exists on EE only
                        if (intval($cabin[2])) continue;
                            $cabin = array($cabin[1],$cabin[4],trim($cabin[5].' '.($cabin[8]!='NULL'?$cabin[8]:'').' '.($cabin[10]!='NULL'?$cabin[10]:'')),$cabin[0],$cabin[20],$cabin[3]);
                    } else {
                        $cabin = array();
                    }
                }
                if ($cabin) $cabins[] = $cabin;
            }
        }
        return $cabins;
    }      
    private function addHttps($url){
        if (empty($_SERVER['HTTPS'])) {
            return $url;
        } elseif ($_SERVER['HTTPS'] == "on") {
            return str_replace('http://', 'https://', $url);
        } else {
            return $url;
        }
    }

    /** Use Omniva API library **/
    public function fetchUpdatesFromApiLib() {
        require_once DIR_SYSTEM . 'omnivalt_lib/autoload.php';

        try {
            $api_pickupPoints = new \Mijora\Omniva\Locations\PickupPoints();

            $terminals = $api_pickupPoints->getFilteredLocations('', 0); //Get only parcel terminals
            $this->saveTerminals($terminals);
            return array(
                'status' => 'success',
                'msg' => 'Omniva terminals updated'
            );
        } catch (\Mijora\Omniva\OmnivaException $e) {
            return array(
                'status' => 'error',
                'msg' => $e->getMessage()
            );
        }
    }
    private function saveTerminals( $terminals_org_list ) {
        $terminals = array();

        foreach ( $terminals_org_list as $terminal ) {
            $address = $terminal['A2_NAME'];
            if ( ! empty($terminal['A5_NAME']) ) {
                $address .= ' ' . $terminal['A5_NAME'];
            }
            if ( ! empty($terminal['A7_NAME']) ) {
                $address .= ' ' . $terminal['A7_NAME'];
            }

            $comment = (! empty($terminal['comment_eng'])) ? $terminal['comment_eng'] : '';
            $comment_map = array(
                'LT' => 'comment_lit',
                'LV' => 'comment_lav',
                'EE' => 'comment_est',
            );
            if ( isset($comment_map[$terminal['A0_NAME']]) && ! empty($terminal[$comment_map[$terminal['A0_NAME']]]) ) {
                $comment = $terminal[$comment_map[$terminal['A0_NAME']]];
            }
            
            $terminals[] = array(
                $terminal['NAME'],
                $terminal['A1_NAME'],
                $address,
                $terminal['ZIP'],
                $comment,
                $terminal['A0_NAME'],
            );
        }

        $key = 'omnivalt_terminals_LT';
        $this->db->query("UPDATE " . DB_PREFIX . "setting 
        SET `value` = '" . $this->db->escape(serialize($terminals)) . "', serialized = '1' 
        WHERE `key` = '" . $this->db->escape($key) . "'");
    }
}
