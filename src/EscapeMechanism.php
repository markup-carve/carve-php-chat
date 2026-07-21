<?php

declare(strict_types=1);

namespace MarkupCarve\Chat;

enum EscapeMechanism: string
{
    case Backslash = 'backslash';
    case Entities = 'entities';
    case None = 'none';
}
