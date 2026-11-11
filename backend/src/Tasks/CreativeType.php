<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * What kind of creative a task asks for. The values are the codes the API sends and the database
 * stores; the frontend translates them. The order is the order the options are listed in.
 */
enum CreativeType: string
{
    case StaticImage = 'static_image';
    case Video = 'video';
    case Carousel = 'carousel';
    case AnimatedGif = 'animated_gif';
    case Html5Banner = 'html5_banner';
    case Other = 'other';
}
