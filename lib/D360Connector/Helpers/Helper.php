<?php

namespace Inbenta\D360Connector\Helpers;

class Helper
{

    /**
     * Valid files formats
     */
    public static $attachableFormats = [
        'image' => ['jpg', 'jpeg', 'png', 'gif'],
        'document' => ['pdf', 'xls', 'xlsx', 'doc', 'docx', 'avi'],
        'video' => ['mp4'],
        'audio' => ['mp3', 'mpeg', 'aac', 'wav', 'wma', 'ogg', 'm4a'],
        'voice' => ['ogg']
    ];

    /**
     * Cleans and keeps the valid HTML tags on Telegram
     * ("li", "ul", "ol", "p". "img", "p" and "iframe" are not valid tags but they are needed and parsed in the next methods)
     * @param string $text
     * @return string $content
     */
    public static function processHtml(string $text)
    {
        $text = html_entity_decode($text, ENT_COMPAT, "UTF-8");
        $content = str_replace(["\r\n", "\r", "\n", "\t"], "", $text);
        $content = strip_tags($content, "<br><b><strong><em><i><ins><del><strike><s><code><pre><a></a><li><ul><ol><p><img><iframe>");
        $content = str_replace("&nbsp;", " ", $content);
        //$content = str_replace("\u00a0", " ", $content);
        $content = str_replace(chr(194) . chr(160), " ", $content);

        $content = str_replace(["<br>", "<br/>", "<br />"], "\n\n", $content);
        $content = str_replace(["<li>", "</li>"], ["\n-", ""], $content);
        $content = str_replace(["<ul>", "<ol>"], "", $content);
        $content = str_replace(["</ul>", "</ol>"], ["\n", "\n"], $content);
        $content = str_replace("<p>", "\n\n", $content);
        $content = str_replace("</p>", "", $content);

        $content = self::acceptedTagText($content);

        return $content;
    }

    /**
     * Format the text if is bold, italic or strikethrough
     * @param string $messageTxt
     * @return string $messageTxt
     */
    public static function acceptedTagText(string $messageTxt)
    {
        $tagsAccepted = ['strong', 'b', 'em', 'i', 's', 'strike'];
        $hasTags = false;
        foreach ($tagsAccepted as $tag) {
            if (strpos($messageTxt, '<' . $tag . '>') !== false) {

                $replaceChar = "*"; //*bold*
                if ($tag === "em" || $tag === "i") $replaceChar = "_"; //_italic_
                else if ($tag === "s" || $tag === "strike") $replaceChar = "~"; //~strikethrough~

                $countTags = substr_count($messageTxt, "<" . $tag . ">");

                $lastPosition = 0;
                $tagArray = [];
                for ($i = 0; $i < $countTags; $i++) {
                    $firstPosition = strpos($messageTxt, "<" . $tag . ">", $lastPosition);
                    $lastPosition = strpos($messageTxt, "</" . $tag . ">", $firstPosition);
                    if ($lastPosition > 0) {
                        $tagLength = strlen($tag) + 3;
                        $tagArray[] = substr($messageTxt, $firstPosition, $lastPosition - $firstPosition + $tagLength);
                    }
                }
                foreach ($tagArray as $oldTag) {
                    $newTag = str_replace("<" . $tag . ">", "", $oldTag);
                    $newTag = str_replace("</" . $tag . ">", "", $newTag);
                    $newTag = $replaceChar . trim($newTag) . $replaceChar . "";
                    $messageTxt = str_replace($oldTag, " " . $newTag . " ", $messageTxt);
                    $hasTags = true;
                }
            }
        }
        $messageTxt = $hasTags ? self::replacePunctuationMarks($messageTxt) : $messageTxt;
        return $messageTxt;
    }

    /**
     * Replace extra spaces in some punctuation marks
     * @param string $messageTxt
     * @return string $messageTxt
     */
    protected static function replacePunctuationMarks(string $messageTxt)
    {
        $messageTxt = str_replace(" ;", ";", $messageTxt);
        $messageTxt = str_replace(" ,", ",", $messageTxt);
        $messageTxt = str_replace(" .", ".", $messageTxt);
        $messageTxt = str_replace(" :", ":", $messageTxt);
        $messageTxt = str_replace(" )", ")", $messageTxt);
        return $messageTxt;
    }

    /**
     * Remove the common html tags from the message and set the final message
     */
    public static function formatFinalMessage($message)
    {
        $message = html_entity_decode($message, ENT_COMPAT, "UTF-8");
        $message = str_replace('&nbsp;', ' ', $message);
        $message = str_replace(["\t"], '', $message);

        $breaks = array("<br />", "<br>", "<br/>", "<p>");
        $message = str_ireplace($breaks, "\n", $message);

        $message = strip_tags($message);

        $rows = explode("\n", $message);
        $messageProcessed = "";
        $previousJump = 0;
        foreach ($rows as $row) {
            if ($row == "" && $previousJump == 0) {
                $previousJump++;
            } else if ($row == "" && $previousJump == 1) {
                $previousJump++;
                $messageProcessed .= "\r\n";
            }
            if ($row !== "") {
                $messageProcessed .= $row . "\r\n";
                $previousJump = 0;
            }
        }
        $messageProcessed = str_replace("  ", " ", $messageProcessed);
        return $messageProcessed;
    }

    /**
     * Clean buttons title from non valid emojis
     * @param string $title
     * @return string $title
     */
    public static function cleanButtonTitle(string $title)
    {
        $titleTmp = json_encode($title);
        if (strpos($titleTmp, "\u200d") > 0) { //Check if exists the invalid string: "\u200d"
            $regex_emoticons = '/[\x{200d}]/u';
            $clear_string = preg_replace($regex_emoticons, '', $title);

            // Match Emoticons
            $regex_emoticons = '/[\x{1F600}-\x{1F64F}]/u';
            $clear_string = preg_replace($regex_emoticons, '', $clear_string);

            // Match Miscellaneous Symbols and Pictographs
            $regex_symbols = '/[\x{1F300}-\x{1F5FF}]/u';
            $clear_string = preg_replace($regex_symbols, '', $clear_string);

            // Match Transport And Map Symbols
            $regex_transport = '/[\x{1F680}-\x{1F6FF}]/u';
            $clear_string = preg_replace($regex_transport, '', $clear_string);

            // Match Miscellaneous Symbols
            $regex_misc = '/[\x{2600}-\x{26FF}]/u';
            $clear_string = preg_replace($regex_misc, '', $clear_string);

            // Match Dingbats
            $regex_dingbats = '/[\x{2700}-\x{27BF}]/u';
            $clear_string = preg_replace($regex_dingbats, '', $clear_string);

            $title = $clear_string;
        }
        return trim($title);
    }

    public static function removeAccentsToLower($string)
    {
        return strtolower(self::removeAccents($string));
    }

    public static function removeAccents($string)
    {
        if (!preg_match('/[\x80-\xff]/', $string))
            return $string;

        $chars = array(
            // Decompositions for Latin-1 Supplement
            chr(195) . chr(128) => 'A', chr(195) . chr(129) => 'A',
            chr(195) . chr(130) => 'A', chr(195) . chr(131) => 'A',
            chr(195) . chr(132) => 'A', chr(195) . chr(133) => 'A',
            chr(195) . chr(135) => 'C', chr(195) . chr(136) => 'E',
            chr(195) . chr(137) => 'E', chr(195) . chr(138) => 'E',
            chr(195) . chr(139) => 'E', chr(195) . chr(140) => 'I',
            chr(195) . chr(141) => 'I', chr(195) . chr(142) => 'I',
            chr(195) . chr(143) => 'I', chr(195) . chr(145) => 'N',
            chr(195) . chr(146) => 'O', chr(195) . chr(147) => 'O',
            chr(195) . chr(148) => 'O', chr(195) . chr(149) => 'O',
            chr(195) . chr(150) => 'O', chr(195) . chr(153) => 'U',
            chr(195) . chr(154) => 'U', chr(195) . chr(155) => 'U',
            chr(195) . chr(156) => 'U', chr(195) . chr(157) => 'Y',
            chr(195) . chr(159) => 's', chr(195) . chr(160) => 'a',
            chr(195) . chr(161) => 'a', chr(195) . chr(162) => 'a',
            chr(195) . chr(163) => 'a', chr(195) . chr(164) => 'a',
            chr(195) . chr(165) => 'a', chr(195) . chr(167) => 'c',
            chr(195) . chr(168) => 'e', chr(195) . chr(169) => 'e',
            chr(195) . chr(170) => 'e', chr(195) . chr(171) => 'e',
            chr(195) . chr(172) => 'i', chr(195) . chr(173) => 'i',
            chr(195) . chr(174) => 'i', chr(195) . chr(175) => 'i',
            chr(195) . chr(177) => 'n', chr(195) . chr(178) => 'o',
            chr(195) . chr(179) => 'o', chr(195) . chr(180) => 'o',
            chr(195) . chr(181) => 'o', chr(195) . chr(182) => 'o',
            chr(195) . chr(182) => 'o', chr(195) . chr(185) => 'u',
            chr(195) . chr(186) => 'u', chr(195) . chr(187) => 'u',
            chr(195) . chr(188) => 'u', chr(195) . chr(189) => 'y',
            chr(195) . chr(191) => 'y',
            // Decompositions for Latin Extended-A
            chr(196) . chr(128) => 'A', chr(196) . chr(129) => 'a',
            chr(196) . chr(130) => 'A', chr(196) . chr(131) => 'a',
            chr(196) . chr(132) => 'A', chr(196) . chr(133) => 'a',
            chr(196) . chr(134) => 'C', chr(196) . chr(135) => 'c',
            chr(196) . chr(136) => 'C', chr(196) . chr(137) => 'c',
            chr(196) . chr(138) => 'C', chr(196) . chr(139) => 'c',
            chr(196) . chr(140) => 'C', chr(196) . chr(141) => 'c',
            chr(196) . chr(142) => 'D', chr(196) . chr(143) => 'd',
            chr(196) . chr(144) => 'D', chr(196) . chr(145) => 'd',
            chr(196) . chr(146) => 'E', chr(196) . chr(147) => 'e',
            chr(196) . chr(148) => 'E', chr(196) . chr(149) => 'e',
            chr(196) . chr(150) => 'E', chr(196) . chr(151) => 'e',
            chr(196) . chr(152) => 'E', chr(196) . chr(153) => 'e',
            chr(196) . chr(154) => 'E', chr(196) . chr(155) => 'e',
            chr(196) . chr(156) => 'G', chr(196) . chr(157) => 'g',
            chr(196) . chr(158) => 'G', chr(196) . chr(159) => 'g',
            chr(196) . chr(160) => 'G', chr(196) . chr(161) => 'g',
            chr(196) . chr(162) => 'G', chr(196) . chr(163) => 'g',
            chr(196) . chr(164) => 'H', chr(196) . chr(165) => 'h',
            chr(196) . chr(166) => 'H', chr(196) . chr(167) => 'h',
            chr(196) . chr(168) => 'I', chr(196) . chr(169) => 'i',
            chr(196) . chr(170) => 'I', chr(196) . chr(171) => 'i',
            chr(196) . chr(172) => 'I', chr(196) . chr(173) => 'i',
            chr(196) . chr(174) => 'I', chr(196) . chr(175) => 'i',
            chr(196) . chr(176) => 'I', chr(196) . chr(177) => 'i',
            chr(196) . chr(178) => 'IJ', chr(196) . chr(179) => 'ij',
            chr(196) . chr(180) => 'J', chr(196) . chr(181) => 'j',
            chr(196) . chr(182) => 'K', chr(196) . chr(183) => 'k',
            chr(196) . chr(184) => 'k', chr(196) . chr(185) => 'L',
            chr(196) . chr(186) => 'l', chr(196) . chr(187) => 'L',
            chr(196) . chr(188) => 'l', chr(196) . chr(189) => 'L',
            chr(196) . chr(190) => 'l', chr(196) . chr(191) => 'L',
            chr(197) . chr(128) => 'l', chr(197) . chr(129) => 'L',
            chr(197) . chr(130) => 'l', chr(197) . chr(131) => 'N',
            chr(197) . chr(132) => 'n', chr(197) . chr(133) => 'N',
            chr(197) . chr(134) => 'n', chr(197) . chr(135) => 'N',
            chr(197) . chr(136) => 'n', chr(197) . chr(137) => 'N',
            chr(197) . chr(138) => 'n', chr(197) . chr(139) => 'N',
            chr(197) . chr(140) => 'O', chr(197) . chr(141) => 'o',
            chr(197) . chr(142) => 'O', chr(197) . chr(143) => 'o',
            chr(197) . chr(144) => 'O', chr(197) . chr(145) => 'o',
            chr(197) . chr(146) => 'OE', chr(197) . chr(147) => 'oe',
            chr(197) . chr(148) => 'R', chr(197) . chr(149) => 'r',
            chr(197) . chr(150) => 'R', chr(197) . chr(151) => 'r',
            chr(197) . chr(152) => 'R', chr(197) . chr(153) => 'r',
            chr(197) . chr(154) => 'S', chr(197) . chr(155) => 's',
            chr(197) . chr(156) => 'S', chr(197) . chr(157) => 's',
            chr(197) . chr(158) => 'S', chr(197) . chr(159) => 's',
            chr(197) . chr(160) => 'S', chr(197) . chr(161) => 's',
            chr(197) . chr(162) => 'T', chr(197) . chr(163) => 't',
            chr(197) . chr(164) => 'T', chr(197) . chr(165) => 't',
            chr(197) . chr(166) => 'T', chr(197) . chr(167) => 't',
            chr(197) . chr(168) => 'U', chr(197) . chr(169) => 'u',
            chr(197) . chr(170) => 'U', chr(197) . chr(171) => 'u',
            chr(197) . chr(172) => 'U', chr(197) . chr(173) => 'u',
            chr(197) . chr(174) => 'U', chr(197) . chr(175) => 'u',
            chr(197) . chr(176) => 'U', chr(197) . chr(177) => 'u',
            chr(197) . chr(178) => 'U', chr(197) . chr(179) => 'u',
            chr(197) . chr(180) => 'W', chr(197) . chr(181) => 'w',
            chr(197) . chr(182) => 'Y', chr(197) . chr(183) => 'y',
            chr(197) . chr(184) => 'Y', chr(197) . chr(185) => 'Z',
            chr(197) . chr(186) => 'z', chr(197) . chr(187) => 'Z',
            chr(197) . chr(188) => 'z', chr(197) . chr(189) => 'Z',
            chr(197) . chr(190) => 'z', chr(197) . chr(191) => 's'
        );

        $string = strtr($string, $chars);

        return $string;
    }
}
