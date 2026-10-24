<?php

namespace Chat\Enums;

enum ConversationType: string
{
    case Private = 'private';
    case Group = 'group';
}
