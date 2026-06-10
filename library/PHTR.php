<?php

/**
 * ============================================================================
 * Class: PHTR
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */



class PHTR {

    /**
     * List of translation server URLs.
     * 
     * @var array
     */
    private static $servers = [
        'google' => 'https://translate.googleapis.com/translate_a/single',
        'openai' => 'https://api.openai.com/v1/engines/davinci-codex/completions',
        'yandex' => 'https://translate.yandex.net/api/v1/tr.json/translate',
        'libre' => 'https://libretranslate.de/translate',
        'lingva' => 'https://lingva.ml/api/v1/{source}/{target}/{text}',
        'mymemory' => 'https://api.mymemory.translated.net/get?q={text}&langpair={source}|{target}',
        'reverso' => 'https://api.reverso.net/translate/v1/translation',
        'yantra' => 'https://api.yantra.ai/translate',
        'deepl' => 'https://api-free.deepl.com/v2/translate',
        'papago' => 'https://openapi.naver.com/v1/papago/n2mt',
        'argosopentech' => 'https://translate.argosopentech.com/translate',
        'baidu' => 'https://fanyi.baidu.com/sug',
        'youdao' => 'http://fanyi.youdao.com/translate',
    ];

    /**
     * Default source language for translations.
     * 
     * @var string
     */
    private static $defaultSourceLanguage = 'auto';

    /**
     * Default target language for translations.
     * 
     * @var string
     */
    private static $defaultTargetLanguage = 'en';

    /**
     * Language mappings for various language codes and identifiers.
     * 
     * @var array
     */
    private static $languageMapping = [
        'en' => ['en', 'eng', 'english', 'us', 'uk', 'usa', 'british', 'america', 'gb', 'london', 'washington', 'newyork', 'asia/london', 'english_us', 'english_uk', 'united states', 'united kingdom', 'dollar', 'GMT-5'],
        'bn' => ['bn', 'bengali', 'bangla', 'bangladesh', 'india', 'bd', 'in', 'asia/dhaka', 'dhaka', 'কলকাতা', 'bengal', 'bengali_language', 'taka', 'GMT+6'],
        'es' => ['es', 'spanish', 'spain', 'espana', 'mexico', 'mx', 'argentina', 'ar', 'latinamerica', 'espanol', 'es-es', 'es-mx', 'madrid', 'peso', 'GMT+1'],
        'fr' => ['fr', 'french', 'france', 'francais', 'paris', 'fr-be', 'belgium', 'canada', 'quebec', 'fr-ca', 'french_canada', 'french_belgium', 'francophone', 'euro', 'GMT+1'],
        'de' => ['de', 'german', 'deutsch', 'germany', 'de-de', 'berlin', 'vienna', 'austria', 'switzerland', 'ch', 'german_swiss', 'euro', 'GMT+1'],
        'ru' => ['ru', 'russian', 'russia', 'moscow', 'ru-ru', 'русский', 'руссия', 'rouble', 'GMT+3'],
        'ar' => ['ar', 'arabic', 'arab', 'saudi', 'ksa', 'uae', 'egypt', 'lebanon', 'syrian', 'arabic_saudi', 'arabic_uae', 'arabic_egypt', 'ar-sa', 'ar-ae', 'arabic_syria', 'riyal', 'GMT+3'],
        'zh' => ['zh', 'chinese', 'mandarin', 'china', 'zh-cn', 'zh-tw', 'taiwan', 'beijing', 'shanghai', 'hongkong', 'asia/shanghai', 'zh-hans', 'zh-hant', 'taiwanese', 'yuan', 'GMT+8'],
        'hi' => ['hi', 'hindi', 'india', 'indian', 'in', 'asia/kolkata', 'mumbai', 'delhi', 'hindi_india', 'rupee', 'GMT+5:30'],
        'ja' => ['ja', 'japanese', 'japan', 'tokyo', 'asia/tokyo', 'japanese_japan', 'yen', 'GMT+9'],
        'ko' => ['ko', 'korean', 'korea', 'southkorea', 'seoul', 'northkorea', 'pyongyang', 'korean_seoul', 'won', 'GMT+9'],
        'pt' => ['pt', 'portuguese', 'portugal', 'brazil', 'pt-pt', 'pt-br', 'lisbon', 'rio', 'portuguese_brazil', 'real', 'GMT-3'],
        'it' => ['it', 'italian', 'italy', 'rome', 'milan', 'italian_italy', 'euro', 'GMT+1'],
        'nl' => ['nl', 'dutch', 'netherlands', 'holland', 'amsterdam', 'belgium', 'nl-nl', 'dutch_netherlands', 'euro', 'GMT+1'],
        'tr' => ['tr', 'turkish', 'turkey', 'ankara', 'istanbul', 'turkish_turkey', 'lira', 'GMT+3'],
        'el' => ['el', 'greek', 'greece', 'athens', 'greek_greece', 'euro', 'GMT+2'],
        'pl' => ['pl', 'polish', 'poland', 'warsaw', 'polish_poland', 'zloty', 'GMT+1'],
        'sv' => ['sv', 'swedish', 'sweden', 'stockholm', 'swedish_sweden', 'krona', 'GMT+1'],
        'no' => ['no', 'norwegian', 'norway', 'oslo', 'norwegian_norway', 'krona', 'GMT+1'],
        'da' => ['da', 'danish', 'denmark', 'copenhagen', 'danish_denmark', 'krona', 'GMT+1'],
        'fi' => ['fi', 'finnish', 'finland', 'helsinki', 'finnish_finland', 'euro', 'GMT+2'],
        'cs' => ['cs', 'czech', 'czechrepublic', 'prague', 'czech_czechrepublic', 'koruna', 'GMT+1'],
        'hu' => ['hu', 'hungarian', 'hungary', 'budapest', 'hungarian_hungary', 'forint', 'GMT+1'],
        'ro' => ['ro', 'romanian', 'romania', 'bucharest', 'romanian_romania', 'leu', 'GMT+2'],
        'sk' => ['sk', 'slovak', 'slovakia', 'bratislava', 'slovak_slovakia', 'euro', 'GMT+1'],
        'uk' => ['uk', 'ukrainian', 'ukraine', 'kyiv', 'lviv', 'ukrainian_ukraine', 'hryvnia', 'GMT+3'],
        'bg' => ['bg', 'bulgarian', 'bulgaria', 'sofia', 'bulgarian_bulgaria', 'lev', 'GMT+2'],
        'sr' => ['sr', 'serbian', 'serbia', 'belgrade', 'serbian_serbia', 'dinar', 'GMT+1'],
        'hr' => ['hr', 'croatian', 'croatia', 'zagreb', 'croatian_croatia', 'kuna', 'GMT+1'],
        'he' => ['he', 'hebrew', 'israel', 'jerusalem', 'hebrew_israel', 'shekel', 'GMT+2'],
        'th' => ['th', 'thai', 'thailand', 'bangkok', 'thai_thailand', 'baht', 'GMT+7'],
        'vi' => ['vi', 'vietnamese', 'vietnam', 'hanoi', 'ho_chi_minh', 'vietnamese_vietnam', 'dong', 'GMT+7'],
        'id' => ['id', 'indonesian', 'indonesia', 'jakarta', 'indonesian_indonesia', 'rupiah', 'GMT+7'],
        'ms' => ['ms', 'malay', 'malaysia', 'kuala_lumpur', 'singapore', 'brunei', 'malay_malaysia', 'ringgit', 'GMT+8'],
        'tl' => ['tl', 'filipino', 'philippines', 'manila', 'filipino_philippines', 'peso', 'GMT+8'],
        'fa' => ['fa', 'persian', 'iran', 'tehran', 'persian_iran', 'rial', 'GMT+3:30'],
        'af' => ['af', 'afrikaans', 'southafrica', 'pretoria', 'cape_town', 'afrikaans_southafrica', 'rand', 'GMT+2'],
        'sw' => ['sw', 'swahili', 'tanzania', 'kenya', 'dar_es_salaam', 'nairobi', 'swahili_tanzania', 'shilling', 'GMT+3'],
        'et' => ['et', 'estonian', 'estonia', 'tallinn', 'estonian_estonia', 'euro', 'GMT+2'],
        'lv' => ['lv', 'latvian', 'latvia', 'riga', 'latvian_latvia', 'euro', 'GMT+2'],
        'lt' => ['lt', 'lithuanian', 'lithuania', 'vilnius', 'lithuanian_lithuania', 'euro', 'GMT+2'],
        'sl' => ['sl', 'slovenian', 'slovenia', 'ljubljana', 'slovenian_slovenia', 'euro', 'GMT+1'],
        'mt' => ['mt', 'maltese', 'malta', 'valletta', 'maltese_malta', 'euro', 'GMT+1'],
        'is' => ['is', 'icelandic', 'iceland', 'reykjavik', 'icelandic_iceland', 'krona', 'GMT+0'],
        'ga' => ['ga', 'irish', 'ireland', 'dublin', 'irish_ireland', 'euro', 'GMT+0'],
        'cy' => ['cy', 'welsh', 'wales', 'cardiff', 'welsh_wales', 'pound', 'GMT+0'],
        'xh' => ['xh', 'xhosa', 'southafrica', 'xhosa_southafrica', 'rand', 'GMT+2'],
        'zu' => ['zu', 'zulu', 'southafrica', 'zulu_southafrica', 'rand', 'GMT+2'],
        'tn' => ['tn', 'tswana', 'southafrica', 'tswana_southafrica', 'rand', 'GMT+2'],
        'sn' => ['sn', 'shona', 'zimbabwe', 'shona_zimbabwe', 'rand', 'GMT+2'],
        'ne' => ['ne', 'nepali', 'nepal', 'kathmandu', 'nepali_nepal', 'rupee', 'GMT+5:45'],
        'km' => ['km', 'khmer', 'cambodia', 'phnom_penh', 'khmer_cambodia', 'riel', 'GMT+7'],
        'si' => ['si', 'sinhala', 'sri lanka', 'colombo', 'sinhala_srilanka', 'rupee', 'GMT+5:30'],
        'mi' => ['mi', 'maori', 'newzealand', 'auckland', 'maori_newzealand', 'dollar', 'GMT+12'],
        'iu' => ['iu', 'inuktitut', 'canada', 'iqaluit', 'inuktitut_canada', 'dollar', 'GMT-5'],
        'ny' => ['ny', 'nyanja', 'malawi', 'lilongwe', 'nyanja_malawi', 'kwacha', 'GMT+2'],
        'so' => ['so', 'somali', 'somalia', 'mogadishu', 'somali_somalia', 'shilling', 'GMT+3'],
        'ha' => ['ha', 'hausa', 'nigeria', 'abuja', 'hausa_nigeria', 'naira', 'GMT+1'],
        'ti' => ['ti', 'tigrinya', 'eritrea', 'asmara', 'tigrinya_eritrea', 'nakfa', 'GMT+3'],
        'am' => ['am', 'amharic', 'ethiopia', 'addis_ababa', 'amharic_ethiopia', 'birr', 'GMT+3'],
        'sq' => ['sq', 'albanian', 'shqip', 'albania', 'al', 'tirana', 'GMT+1', 'lek'],
        'ml' => ['ml', 'malayalam', '🇮🇳', 'in', 'india', 'kerala', 'thiruvananthapuram', 'india', 'rupee','GMT+5:30', 'malayalam_india','മലയാളം', 'കേരളം', 'തിരുവനന്തപുരം' ],
        'fr-CA' => ['fr-CA', 'french', 'français', 'canada', 'ca', 'ottawa', 'GMT-5', 'french_canada', 'quebec', 'français_canada'],
        'en-CA' => ['en-CA', 'english', 'anglais', 'canada', 'ca', 'ottawa', 'GMT-5', 'english_canada'],
        'en-AU' => ['en-AU', 'english', 'anglais', 'australia', 'au', 'canberra', 'GMT+10', 'english_australia', 'aussie', 'oz'],
        'pt-BR' => ['pt-BR', 'portuguese', 'português', 'brazil', 'br', 'brasilia', 'GMT-3', 'portuguese_brazil'],
        'en-NZ' => ['en-NZ', 'english', 'anglais', 'new zealand', 'nz', 'wellington', 'GMT+13', 'english_newzealand'],
        'de-AT' => ['de-AT', 'german', 'deutsch', 'austria', 'at', 'vienna', 'GMT+1', 'german_austria'],
        'de-CH' => ['de-CH', 'german', 'deutsch', 'switzerland', 'ch', 'bern', 'GMT+1', 'german_swiss'],
        'en-IE' => ['en-IE', 'english', 'anglais', 'ireland', 'ie', 'dublin', 'GMT+0', 'english_ireland'],
        'en-ZA' => ['en-ZA', 'english', 'anglais', 'south africa', 'za', 'pretoria', 'GMT+2', 'english_southafrica'],
        'es-MX' => ['es-MX', 'spanish', 'español', 'mexico', 'mx', 'mexico city', 'GMT-6', 'spanish_mexico'],
        'es-AR' => ['es-AR', 'spanish', 'español', 'argentina', 'ar', 'buenos aires', 'GMT-3', 'spanish_argentina'],
        'es-CO' => ['es-CO', 'spanish', 'español', 'colombia', 'co', 'bogotá', 'GMT-5', 'spanish_colombia'],
        'ja-JP' => ['ja-JP', 'japanese', '日本語', 'japan', 'jp', 'tokyo', 'GMT+9', 'japanese_japan'],
        'ko-KR' => ['ko-KR', 'korean', '한국어', 'south korea', 'kr', 'seoul', 'GMT+9', 'korean_southkorea'],
        'ar-MA' => ['ar-MA', 'arabic', 'العربية', 'morocco', 'ma', 'rabat', 'GMT+1', 'arabic_morocco'],
        'ar-DZ' => ['ar-DZ', 'arabic', 'العربية', 'algeria', 'dz', 'algiers', 'GMT+1', 'arabic_algeria'],
        'ar-EG' => ['ar-EG', 'arabic', 'العربية', 'egypt', 'eg', 'cairo', 'GMT+2', 'arabic_egypt'],
        'vi-VN' => ['vi-VN', 'vietnamese', 'tiếng việt', 'vietnam', 'vn', 'hanoi', 'GMT+7', 'vietnamese_vietnam'],
        'hi-IN' => ['hi-IN', 'hindi', 'हिन्दी', 'india', 'in', 'new delhi', 'GMT+5:30', 'hindi_india'],
        'ta-IN' => ['ta-IN', 'tamil', 'தமிழ்', 'india', 'in', 'chennai', 'GMT+5:30', 'tamil_india'],
        'bn-IN' => ['bn-IN', 'bengali', 'বাংলা', 'india', 'in', 'kolkata', 'GMT+5:30', 'bengali_india'],
        'ml-IN' => ['ml-IN', 'malayalam', 'മലയാളം', 'india', 'in', 'thiruvananthapuram', 'GMT+5:30', 'malayalam_india'],
        'gu-IN' => ['gu-IN', 'gujarati', 'ગુજરાતી', 'india', 'in', 'gandhinagar', 'GMT+5:30', 'gujarati_india'],
        'pa-IN' => ['pa-IN', 'punjabi', 'ਪੰਜਾਬੀ', 'india', 'in', 'chandigarh', 'GMT+5:30', 'punjabi_india'],
        'or-IN' => ['or-IN', 'odia', 'ଓଡ଼ିଆ', 'india', 'in', 'bhubaneswar', 'GMT+5:30', 'odia_india'],
        'si-LK' => ['si-LK', 'sinhala', 'සිංහල', 'sri lanka', 'lk', 'colombo', 'GMT+5:30', 'sinhala_srilanka'],
        'tl-PH' => ['tl-PH', 'filipino', 'Filipino', 'philippines', 'ph', 'manila', 'GMT+8', 'filipino_philippines'],
        'fr-BE' => ['fr-BE', 'french', 'français', 'belgium', 'be', 'brussels', 'GMT+1', 'french_belgium'],
        'fr-CH' => ['fr-CH', 'french', 'français', 'switzerland', 'ch', 'bern', 'GMT+1', 'french_swiss'],
        'el-GR' => ['el-GR', 'greek', 'ελληνικά', 'greece', 'gr', 'athens', 'GMT+2', 'greek_greece'],
        'hu-HU' => ['hu-HU', 'hungarian', 'magyar', 'hungary', 'hu', 'budapest', 'GMT+1', 'hungarian_hungary'],
        'cs-CZ' => ['cs-CZ', 'czech', 'čeština', 'czech republic', 'cz', 'prague', 'GMT+1', 'czech_czechrepublic'],
        'ro-RO' => ['ro-RO', 'romanian', 'română', 'romania', 'ro', 'bucharest', 'GMT+2', 'romanian_romania'],
        'sk-SK' => ['sk-SK', 'slovak', 'slovenčina', 'slovakia', 'sk', 'bratislava', 'GMT+1', 'slovak_slovakia'],
        'lv-LV' => ['lv-LV', 'latvian', 'latviešu', 'latvia', 'lv', 'riga', 'GMT+2', 'latvian_latvia'],
        'lt-LT' => ['lt-LT', 'lithuanian', 'lietuvių', 'lithuania', 'lt', 'vilnius', 'GMT+2', 'lithuanian_lithuania'],
        'et-EE' => ['et-EE', 'estonian', 'eesti', 'estonia', 'ee', 'tallinn', 'GMT+2', 'estonian_estonia'],
        'sw-TZ' => ['sw-TZ', 'swahili', 'kiswahili', 'tanzania', 'tz', 'dodoma', 'GMT+3', 'swahili_tanzania'],
        'sw-KE' => ['sw-KE', 'swahili', 'kiswahili', 'kenya', 'ke', 'nairobi', 'GMT+3', 'swahili_kenya'],
        'ar-SY' => ['ar-SY', 'arabic', 'العربية', 'syria', 'sy', 'damascus', 'GMT+3', 'arabic_syria'],
        'ar-LY' => ['ar-LY', 'arabic', 'العربية', 'libya', 'ly', 'tripoli', 'GMT+2', 'arabic_libya'],
        'es-PE' => ['es-PE', 'spanish', 'español', 'peru', 'pe', 'lima', 'GMT-5', 'spanish_peru'],
        'es-CL' => ['es-CL', 'spanish', 'español', 'chile', 'cl', 'santiago', 'GMT-3', 'spanish_chile'],
        'es-EC' => ['es-EC', 'spanish', 'español', 'ecuador', 'ec', 'quito', 'GMT-5', 'spanish_ecuador'],
        'es-CR' => ['es-CR', 'spanish', 'español', 'costa rica', 'cr', 'san jose', 'GMT-6', 'spanish_costarica'],
        'it-IT' => ['it-IT', 'italian', 'italiano', 'italy', 'it', 'rome', 'GMT+1', 'italian_italy'],
        'da-DK' => ['da-DK', 'danish', 'dansk', 'denmark', 'dk', 'copenhagen', 'GMT+1', 'danish_denmark'],
        'no-NO' => ['no-NO', 'norwegian', 'norsk', 'norway', 'no', 'oslo', 'GMT+1', 'norwegian_norway'],
        'fi-FI' => ['fi-FI', 'finnish', 'suomi', 'finland', 'fi', 'helsinki', 'GMT+2', 'finnish_finland'],
        'sv-SE' => ['sv-SE', 'swedish', 'svenska', 'sweden', 'se', 'stockholm', 'GMT+1', 'swedish_sweden'],
        'is-IS' => ['is-IS', 'icelandic', 'íslenska', 'iceland', 'is', 'reykjavik', 'GMT+0', 'icelandic_iceland'],
        'mt-MT' => ['mt-MT', 'maltese', 'malti', 'malta', 'mt', 'valletta', 'GMT+1', 'maltese_malta'],
        'uk-UA' => ['uk-UA', 'ukrainian', 'українська', 'ukraine', 'ua', 'kyiv', 'GMT+2', 'ukrainian_ukraine']
    ];

    /**
     * Cache for storing previously translated texts.
     * 
     * @var array
     */
    private static $translatedCache = [];

    /**
     * Translate a given input using a specified translation server.
     *
     * @param mixed  $input      The input text or array to translate.
     * @param string $serverName The name of the translation server to use.
     * @param string $source     The source language code.
     * @param string $target     The target language code.
     * @return mixed             Translated text or array.
     */
    public static function translate($input, $serverName = 'google', $source = 'auto', $target = 'English') {
        $sourceLang = self::getLanguageCode($source);
        $targetLang = self::getLanguageCode($target);

        if (is_array($input)) {
            $results = [];
            foreach ($input as $key => $value) {
                if (is_array($value)) {
                    $results[$key] = self::translate($value, $serverName, $sourceLang, $targetLang);
                } else {
                    $results[$key] = self::translateSingle($value, $serverName, $sourceLang, $targetLang);
                }
            }
            return $results;
        } else {
            return self::translateSingle($input, $serverName, $sourceLang, $targetLang);
        }
    }

    /**
     * Automatically detect and translate using all available servers.
     *
     * @param mixed  $input          The text or array to translate.
     * @param string $targetLanguage The target language code.
     * @return mixed                 Translated text or false on failure.
     */
    public static function auto($input, $targetLanguage = 'en') {
        $sourceLang = self::$defaultSourceLanguage;
        $targetLang = self::getLanguageCode($targetLanguage);

        foreach (self::$servers as $serverName => $serverUrl) {
            $result = self::translate($input, $serverName, $sourceLang, $targetLang);
            if ($result !== false) {
                return $result;
            }
        }

        return false;
    }

    /**
     * Translate a single text using a specified server.
     *
     * @param string $text       The text to translate.
     * @param string $serverName The translation server to use.
     * @param string $sourceLang The source language code.
     * @param string $targetLang The target language code.
     * @return string|false      Translated text or false on failure.
     */
    private static function translateSingle($text, $serverName, $sourceLang, $targetLang) {
        if (empty($text)) {
            return $text;
        }

        if (self::isTranslated($text, $targetLang)) {
            return self::$translatedCache[$targetLang][$text];
        }

        $url = self::buildUrl($serverName, $text, $sourceLang, $targetLang);

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $translatedText = self::parseResponse($response, $serverName);
        self::$translatedCache[$targetLang][$text] = $translatedText;

        return $translatedText ?? $text;
    }

    /**
     * Build the appropriate translation URL based on the server name.
     *
     * @param string $serverName The name of the translation server.
     * @param string $text       The text to translate.
     * @param string $sourceLang The source language code.
     * @param string $targetLang The target language code.
     * @return string|null       Constructed URL or null on failure.
     */
    public static function buildUrl($serverName, $text, $sourceLang, $targetLang) {
        switch ($serverName) {
            case 'google':
                return self::$servers[$serverName] . '?client=gtx&sl=' . $sourceLang . '&tl=' . $targetLang . '&dt=t&q=' . urlencode($text);
            
            case 'libre':
                return self::$servers[$serverName] . '?q=' . urlencode($text) . '&source=' . $sourceLang . '&target=' . $targetLang;
            
            case 'lingva':
                $lingvaUrl = self::$servers[$serverName];
                return str_replace(['{source}', '{target}', '{text}'], [$sourceLang, $targetLang, urlencode($text)], $lingvaUrl);
            
            case 'mymemory':
                return str_replace(['{source}', '{target}', '{text}'], [$sourceLang, $targetLang, urlencode($text)], self::$servers[$serverName]);
            
            case 'reverso':
                return self::$servers[$serverName];
            
            case 'yantra':
                return self::$servers[$serverName] . '?source=' . $sourceLang . '&target=' . $targetLang . '&text=' . urlencode($text);
            
            case 'deepl':
                return self::$servers[$serverName] . '?auth_key=YOUR_DEEPL_API_KEY&text=' . urlencode($text) . '&source_lang=' . $sourceLang . '&target_lang=' . $targetLang;
            
            case 'papago':
                return self::$servers[$serverName];
            
            case 'argosopentech':
                return self::$servers[$serverName] . '?source=' . $sourceLang . '&target=' . $targetLang . '&q=' . urlencode($text);
            
            case 'baidu':
                return self::$servers[$serverName] . '?kw=' . urlencode($text);
            
            case 'youdao':
                return self::$servers[$serverName] . '?doctype=json&type=AUTO&i=' . urlencode($text);
            
            default:
                return null;
        }
    }

    /**
     * Parses the response from the translation server based on the server name.
     * Extracts the translated text from the API response using different patterns for each server.
     *
     * @param string $response   The raw response from the translation server in JSON format.
     * @param string $serverName The name of the translation server (e.g., 'google', 'libre').
     * @return string|null       Returns the translated text if successful, or null if parsing failed.
     */
    public static function parseResponse($response, $serverName) {
        $translatedText = null;
        switch ($serverName) {
            case 'google':
                $translatedText = json_decode($response, true);
                return $translatedText[0][0][0] ?? null;
            
            case 'libre':
                $translatedText = json_decode($response, true);
                return $translatedText['translatedText'] ?? null;
            
            case 'lingva':
                $translatedText = json_decode($response, true);
                return $translatedText['translation'] ?? null;
            
            case 'mymemory':
                $translatedText = json_decode($response, true);
                return $translatedText['responseData']['translatedText'] ?? null;
            
            case 'reverso':
                $translatedText = json_decode($response, true);
                return $translatedText['translation'][0]['translatedText'] ?? null;
            
            case 'yantra':
                $translatedText = json_decode($response, true);
                return $translatedText['translated_text'] ?? null;
            
            case 'deepl':
                $translatedText = json_decode($response, true);
                return $translatedText['translations'][0]['text'] ?? null;
            
            case 'papago':
                $translatedText = json_decode($response, true);
                return $translatedText['message']['result']['translatedText'] ?? null;
            
            case 'argosopentech':
                $translatedText = json_decode($response, true);
                return $translatedText['translatedText'] ?? null;
            
            case 'baidu':
                $translatedText = json_decode($response, true);
                return $translatedText['data'][0]['v'] ?? null;
            
            case 'youdao':
                $translatedText = json_decode($response, true);
                return $translatedText['translateResult'][0][0]['tgt'] ?? null;
            
            default:
                return null;
        }
    }

    /**
     * Checks if the provided text has been previously translated into the specified target language.
     * Utilizes a cache (`self::$translatedCache`) to avoid redundant translations.
     *
     * @param string $text       The original text to be checked.
     * @param string $targetLang The language code of the target translation language (e.g., 'en', 'fr').
     * @return bool              Returns true if the text is already translated and cached, false otherwise.
     */
    private static function isTranslated($text, $targetLang) {
        return isset(self::$translatedCache[$targetLang][$text]);
    }

    /**
     * Maps a given language input to its corresponding language code.
     * Supports various aliases and identifiers for different languages using the `$languageMapping` array.
     *
     * @param string $lang The input language name, code, or alias (e.g., 'english', 'fr-ca', 'bangla').
     * @return string      Returns the standardized language code (e.g., 'en', 'fr', 'bn').
     */
    private static function getLanguageCode($lang) {
        $lang = strtolower($lang);
        foreach (self::$languageMapping as $code => $identifiers) {
            if (in_array($lang, $identifiers)) {
                return $code;
            }
        }
        return self::$defaultTargetLanguage;
    }
}
?>