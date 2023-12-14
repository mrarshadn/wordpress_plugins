<?php

/*
Plugin Name: Webtive Parser
Description: This plugin will be used to parse listing from Webtive.co.il and this will save them to properties
Plugin URI: https://github.com/mrarshadn/wordpress_plugins/webtiv-parser
Version: 1.0
Author: Muhammad Arshad
*/


if (!defined('WPINC')) {
    die;
}

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

class WebitvParser
{
    private $properties;
    private $translations = [];
    private $REMOTE_XML = '';

    private $customFieldmappings = [
        'priceshekel' => 'es_property_price',
        'shcuna' => 'es_property_neighborhood',
        'room' => 'es_property_total_rooms',
        'ac' => 'es_property_cooling',
        'mirpesetShemeshYN' => 'es_property_porch',
        'soragimYN' => 'es_property_security_bars',
        'mamadYN' => 'es_property_security_room',
        'meshupatsYN' => 'es_property_renovated',
        'ginasqmr' => 'es_property_garden',
        'builtsqmr' => 'es_property_area',
    ];
    private $translateFields = ["objecttype", "city", "shcuna", "street", "lift", "park", "kitchen", "toilet", "a_c", "boiler", 'mainaction', 'removal', 'rihut', 'comments2'];

    private $translationFile = __DIR__ . '/translations.json';

    public function __construct()
    {
        if (file_exists($this->translationFile)) {
            $this->translations = json_decode(file_get_contents($this->translationFile), true);
        } else {
            touch($this->translationFile);
        }
        $this->getRemoteData();
    }

    public function getPostFromDb($externalId)
    {
        $args = array(
            'meta_query' => array(
                array(
                    'key' => 'es_property_external_id',
                    'value' => $externalId
                )
            ),
            'post_type' => (class_exists('Es_Property')) ? Es_Property::get_post_type_name() : 'properties',
            'posts_per_page' => -1
        );

        $posts = get_posts($args);
        if ($posts && count($posts)) {
            return reset($posts);
        }
        return null;
    }

    public function translateText($sourceText)
    {
        $url = implode('', [
            'https://translate.googleapis.com/translate_a/single',
            '?client=gtx',
            '&dt=t',
            '&dj=1',
            '&sl=he',
            '&tl=en',
            '&q=' . urlencode($sourceText),
        ]);
        $response = json_decode(file_get_contents($url));
        if ($response && isset($response->sentences, $response->sentences[0], $response->sentences[0]->trans)) {
            return $response->sentences[0]->trans;
        }
        return null;
    }

    public function getRemoteData($xmlUrl = '')
    {
        if (!$xmlUrl) {
            $xmlUrl = $this->REMOTE_XML;
        }
        try {
            $xml = simplexml_load_file($xmlUrl);
            $data = json_decode(json_encode($xml));

            if (isset($data->Properties)) {
                $properties = [];
                foreach ($data->Properties as $property) {
                    foreach ($property as $key => &$value) {
                        if ((gettype($value) != 'object') && in_array($key, $this->translateFields)) {
                            try {
//                                $value = json_decode($value);
                                if (!isset($this->translations[$value])) {
                                    $this->translations[$value] = $this->translateText($value);
                                }
                                $value = $this->translations[$value];
                            } catch (Exception $e) {
                                dump($e);
                            }
                        }
                    }
                    $property->pictures = [];
                    $properties[$property->serial] = $property;
                }
                file_put_contents($this->translationFile, json_encode($this->translations, JSON_PRETTY_PRINT));
                ksort($properties);
                $this->properties = $properties;
                if (isset($data->pictures)) {
                    foreach ($data->pictures as $picture) {
                        if (isset($this->properties[$picture->picserial])) {
                            if (!isset($this->translations[$picture->mainaction])) {
                                $this->translations[$picture->mainaction] = $this->translateText($picture->mainaction);
                            }
                            $picture->mainaction = $this->translations[$picture->mainaction];

                            $this->properties[$picture->picserial]->pictures[$picture->picID] = $picture;
                        }
                    }
                }
            }
        } catch (Exception $e) {

        }
        return null;
    }

    public function getProperties()
    {
        return $this->properties;
    }

    public function createOrUpdateProperty($externalId, $property)
    {

        $post = $this->getPostFromDb($externalId);

        $metaKeys = new StdClass();
        $metaKeys->es_property_external_id = $externalId;
        $pictures = [];
        foreach ($property as $key => $value) {
//            if($key=='builtsqmr'){
//                $value = 10.764 * $value;
//            }
            if ($key == 'pictures') {
                $pictures = $value;
                unset($key);
                continue;
            }

            if (array_key_exists($key, $this->customFieldmappings)) {
                $metaKeys->{$this->customFieldmappings[$key]} = $value;

            } else {
                $metaKeys->{'es_property_' . $key} = $value;
            }

            unset($key);
        }
        $metaKeys->es_property_address = $metaKeys->es_property_street . ' ' . $metaKeys->es_property_city;

        unset($metaKeys->es_property_street);
        unset($metaKeys->es_property_city);

        $imgIds = [];
        $attachmentModified = false;

        if ($post) {
            $post_id = $post->ID;
            $post->post_title = $metaKeys->es_property_address;
            $post->post_name = sanitize_title($post->post_title);
            $post->meta_input = $metaKeys;

            wp_update_post($post);

//            $propertyData = get_post_custom($post->ID);

            $attachments = get_posts(array(
                'post_type' => 'attachment',
                'posts_per_page' => -1,
                'post_parent' => $post->ID,
                'exclude' => get_post_thumbnail_id()
            ));


            foreach ($attachments as $attachment) {
                $attachmentData = wp_get_attachment_metadata($attachment->ID);
                $picFound = (isset($attachmentData->picID, $pictures[$attachmentData->picID]) ? $pictures[$attachmentData->picID] : null);
                if ($picFound) {
                    if ($attachmentData->picID == $picFound->picID && $attachmentData->file == $picFound->file) {
                        unset($pictures[$attachmentData->picID]);
                    } else if ($attachmentData->picID == $picFound->picID) {
                        // delete old file
                        wp_delete_attachment($attachment->ID);
                        $this->save_new_attachment($attachment->ID, $picFound, $imgIds);
                        $attachmentModified = true;
                    } else if ($attachmentData->file == $picFound->file) {
                        array_push($imgIds, $attachment->ID);
                        // update the picID
                        wp_update_attachment_metadata($attachment->ID, [
                            'picID' => $picFound->picID,
                            'mainaction' => $picFound->mainaction
                        ]);
                        $attachmentModified = true;
                    }
                    unset($pictures[$attachmentData->picID]);
                } else {
                    // Attachment found but not related picture on remote
                    wp_delete_attachment($attachment->ID);
                    $attachmentModified = true;
                }
            }


        } else {
            $post_id = wp_insert_post([
                'post_type' => (class_exists('Es_Property')) ? Es_Property::get_post_type_name() : 'properties',
                'post_status' => 'publish',
                'meta_input' => $metaKeys,
                'post_title' => $metaKeys->es_property_address,
                'post_author' => 1,
            ]);
        }
        foreach ($pictures as $picture) {
            $attachmentModified = true;
            $this->save_new_attachment($post_id, $picture, $imgIds);
        }

        if ($attachmentModified) {
            $esProp = es_get_property($post_id);
            $esProp->save_field_value('gallery', $imgIds);
        }
    }

    public function save_new_attachment($post_id, $picture, &$imgIds)
    {
        $id = media_sideload_image($picture->picurl, $post_id, null, 'id');
        array_push($imgIds, $id);

        $data = wp_generate_attachment_metadata($id, $picture->picurl);
        wp_update_attachment_metadata($id, array_merge((array)$data, [
//            'menu_order'=> $picture->picOrder,
            'picID' => $picture->picID,
            'mainaction' => $picture->mainaction
        ]));
    }

}

add_action('plugins_loaded', 'process_plugin');

function process_plugin()
{
    if (!isset($_REQUEST['runImport'])) {
        return;
    }

    $parser = new WebitvParser();

    $properties = $parser->getProperties();

    $counter = 0;
    $total = count($properties);
    foreach ($properties as $id => $property) {
        echo "Processing $id - " . (++$counter . ' / ' . $total) . "<br> \r\n" . PHP_EOL . PHP_EOL;
        $parser->createOrUpdateProperty($id, $properties[$id]);

        flush();
        ob_flush();
        ob_get_clean();
    }
    ob_clean();
    echo 'completed';
    die;

}