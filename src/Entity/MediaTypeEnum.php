<?php

namespace App\Entity;

enum MediaTypeEnum: string
{
    case VIDEO = 'VIDEO';
    case IMAGE = 'IMAGE';
    case AUDIO = 'AUDIO';
    case DOCUMENT = 'DOCUMENT';
    case ARCHIVE = 'ARCHIVE';
    case OTHER = 'OTHER';
    case UNDEFINED = 'UNDEFINED';

//    /**
//     * Infer category from MIME type.
//     */
//    public static function fromMime(string $mime): MediaTypeEnum
//    {
//        $primary = explode('/', strtolower($mime))[0];
//
//        return match ($primary) {
//            'image' => self::IMAGE,
//            'video' => self::VIDEO,
//            'audio' => self::AUDIO,
//            'text', 'application' => self::DOCUMENT,
//            default => self::OTHER,
//        };
//    }
}
