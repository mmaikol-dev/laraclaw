"use client"

import { useCallback, useEffect, useRef, useState } from 'react';
import { Disc, Mic, Square, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { LiveWaveform } from '@/components/ui/live-waveform';
import { MicSelector } from '@/components/ui/mic-selector';
import { cn } from '@/lib/utils';

interface VoiceInputProps {
    onTranscript: (text: string) => void;
    disabled?: boolean;
}

type VoiceState = 'idle' | 'listening' | 'processing';

// Extend window for browser SpeechRecognition
declare global {
    interface Window {
        SpeechRecognition: typeof SpeechRecognition;
        webkitSpeechRecognition: typeof SpeechRecognition;
    }
}

export function VoiceInput({ onTranscript, disabled }: VoiceInputProps) {
    const [state, setState] = useState<VoiceState>('idle');
    const [selectedDevice, setSelectedDevice] = useState('');
    const [isMuted, setIsMuted] = useState(false);
    const [interim, setInterim] = useState('');
    const [supported, setSupported] = useState(true);

    const recognitionRef = useRef<SpeechRecognition | null>(null);
    const finalRef = useRef('');

    useEffect(() => {
        const SR = window.SpeechRecognition ?? window.webkitSpeechRecognition;
        if (!SR) {
            setSupported(false);
        }
    }, []);

    const stop = useCallback(() => {
        recognitionRef.current?.stop();
        recognitionRef.current = null;
        setState('idle');
        setInterim('');
        finalRef.current = '';
    }, []);

    const start = useCallback(() => {
        const SR = window.SpeechRecognition ?? window.webkitSpeechRecognition;
        if (!SR) return;

        const recognition = new SR();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.lang = 'en-US';

        recognition.onstart = () => setState('listening');

        recognition.onresult = (event) => {
            let interimText = '';
            let finalText = finalRef.current;

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const transcript = event.results[i][0].transcript;
                if (event.results[i].isFinal) {
                    finalText += transcript + ' ';
                } else {
                    interimText += transcript;
                }
            }

            finalRef.current = finalText;
            setInterim(interimText);
        };

        recognition.onerror = () => stop();

        recognition.onend = () => {
            const text = (finalRef.current + interim).trim();
            if (text) {
                onTranscript(text);
            }
            setState('idle');
            setInterim('');
            finalRef.current = '';
            recognitionRef.current = null;
        };

        recognitionRef.current = recognition;
        recognition.start();
    }, [onTranscript, stop, interim]);

    const handleToggle = useCallback(() => {
        if (state === 'listening') {
            recognitionRef.current?.stop();
            setState('processing');
        } else {
            start();
        }
    }, [state, start]);

    useEffect(() => {
        if (isMuted && state === 'listening') {
            recognitionRef.current?.stop();
            setState('processing');
        }
    }, [isMuted, state]);

    useEffect(() => {
        return () => recognitionRef.current?.abort();
    }, []);

    if (!supported) return null;

    const isListening = state === 'listening';

    return (
        <div className={cn(
            'flex items-center gap-1 transition-all duration-200',
            isListening && 'rounded-xl border bg-card px-2 py-1 shadow-md ring-1 ring-red-500/30',
        )}>
            {isListening && (
                <>
                    <MicSelector
                        value={selectedDevice}
                        onValueChange={setSelectedDevice}
                        muted={isMuted}
                        onMutedChange={setIsMuted}
                        disabled={state !== 'listening'}
                        className="w-36 text-xs"
                    />
                    <div className="w-24 overflow-hidden rounded-md">
                        <LiveWaveform
                            active={isListening && !isMuted}
                            deviceId={selectedDevice}
                            mode="scrolling"
                            height={20}
                            barWidth={3}
                            barGap={1}
                            barRadius={4}
                            fadeEdges
                            fadeWidth={16}
                            sensitivity={1.8}
                            smoothingTimeConstant={0.85}
                            className="w-full"
                        />
                    </div>
                    {(finalRef.current || interim) && (
                        <span className="max-w-[140px] truncate text-[11px] text-muted-foreground italic">
                            {(finalRef.current + interim).trim()}
                        </span>
                    )}
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        onClick={stop}
                        className="size-6 text-muted-foreground hover:text-foreground"
                        title="Cancel"
                    >
                        <X className="size-3" />
                    </Button>
                </>
            )}

            <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={handleToggle}
                disabled={disabled || isMuted}
                title={isListening ? 'Stop and send' : 'Start voice input'}
                className={cn(
                    'h-8 w-8 rounded-xl p-0 transition-colors',
                    isListening
                        ? 'bg-red-600 text-white hover:bg-red-700'
                        : 'text-muted-foreground hover:text-foreground',
                )}
            >
                {isListening ? (
                    <Square className="size-3 fill-current" />
                ) : (
                    <Mic className="size-3.5" />
                )}
            </Button>
        </div>
    );
}
