<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('chat/index', [
            'conversationId' => null,
        ]);
    }

    public function show(Conversation $conversation): Response
    {
        return Inertia::render('chat/index', [
            'conversationId' => $conversation->id,
        ]);
    }
}
