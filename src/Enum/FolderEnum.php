<?php

declare(strict_types=1);

namespace Photobooth\Enum;

use Photobooth\Utility\PathUtility;

enum FolderEnum: string
{
    case API = 'api';
    case CHROMA = 'chroma';
    case DATA = 'data';
    case IMAGES = 'data/images';
    case KEYING = 'data/keying';
    case PRINT = 'data/print';
    case QR = 'data/qrcodes';
    case TEST = 'data/test';
    case THUMBS = 'data/thumbs';
    case TEMP = 'data/tmp';
    case LANG = 'resources/lang';
    case PRIVATE = 'private';
    case RESOURCES = 'resources';
    case VAR = 'var';
    case LR_IMPORT = 'data/lr_import';
    case LR_EXPORT = 'data/lr_export';
    case C1_INPUT = 'data/c1_input';
    case C1_OUTPUT = 'data/c1_output';
    case C1_ARCHIVE = 'data/c1_archive';
    case PIPELINE_RAW = 'data/pipeline/01_raw_captures';
    case PIPELINE_PENDING_FRAME = 'data/pipeline/02_pending_frame';
    case PIPELINE_FRAMED = 'data/pipeline/03_framed';
    case PIPELINE_PRINT_INSTANT = 'data/pipeline/04_print_instant';
    case PIPELINE_PRINT_BANQUET = 'data/pipeline/05_print_banquet_pending';
    case PIPELINE_PRINTED = 'data/pipeline/06_printed';

    public function public(): string
    {
        return PathUtility::getPublicPath($this->value);
    }

    public function absolute(): string
    {
        return PathUtility::getAbsolutePath($this->value);
    }

    public function identifier(): string
    {
        return match($this) {
            FolderEnum::API => 'api',
            FolderEnum::CHROMA => 'chroma',
            FolderEnum::DATA => 'data',
            FolderEnum::IMAGES => 'images',
            FolderEnum::KEYING => 'keying',
            FolderEnum::PRINT => 'print',
            FolderEnum::QR => 'qrcodes',
            FolderEnum::TEST => 'test',
            FolderEnum::THUMBS => 'thumbs',
            FolderEnum::TEMP => 'tmp',
            FolderEnum::LANG => 'lang',
            FolderEnum::PRIVATE => 'private',
            FolderEnum::RESOURCES => 'resources',
            FolderEnum::VAR => 'var',
            FolderEnum::LR_IMPORT => 'lr_import',
            FolderEnum::LR_EXPORT => 'lr_export',
            FolderEnum::C1_INPUT => 'c1_input',
            FolderEnum::C1_OUTPUT => 'c1_output',
            FolderEnum::C1_ARCHIVE => 'c1_archive',
            FolderEnum::PIPELINE_RAW => 'pipeline_raw',
            FolderEnum::PIPELINE_PENDING_FRAME => 'pipeline_pending_frame',
            FolderEnum::PIPELINE_FRAMED => 'pipeline_framed',
            FolderEnum::PIPELINE_PRINT_INSTANT => 'pipeline_print_instant',
            FolderEnum::PIPELINE_PRINT_BANQUET => 'pipeline_print_banquet',
            FolderEnum::PIPELINE_PRINTED => 'pipeline_printed',
        };
    }
}
