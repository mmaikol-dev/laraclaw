import { useEffect, useRef, useCallback } from 'react';

interface FadingVideoProps {
    src: string | string[];
    className?: string;
    style?: React.CSSProperties;
}

export default function FadingVideo({
    src,
    className = '',
    style,
}: FadingVideoProps) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const currentIndexRef = useRef(0);
    const fadingOutRef = useRef(false);

    const fadeIn = useCallback((video: HTMLVideoElement, duration = 500) => {
        const start = performance.now();
        video.style.opacity = '0';

        const tick = (now: number) => {
            const elapsed = now - start;
            const progress = Math.min(elapsed / duration, 1);
            video.style.opacity = String(progress);
            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        };

        requestAnimationFrame(tick);
    }, []);

    const fadeOut = useCallback((video: HTMLVideoElement, duration = 550) => {
        if (fadingOutRef.current) return;
        fadingOutRef.current = true;

        const start = performance.now();
        const initialOpacity = parseFloat(video.style.opacity) || 1;

        const tick = (now: number) => {
            const elapsed = now - start;
            const progress = Math.min(elapsed / duration, 1);
            video.style.opacity = String(initialOpacity * (1 - progress));
            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        };

        requestAnimationFrame(tick);
    }, []);

    const advanceSource = useCallback(() => {
        const video = videoRef.current;
        if (!video) return;

        const sources = Array.isArray(src) ? src : [src];
        if (sources.length === 1) {
            video.currentTime = 0;
            fadingOutRef.current = false;
            fadeIn(video);
            video.play().catch(() => {});
            return;
        }

        currentIndexRef.current =
            (currentIndexRef.current + 1) % sources.length;
        video.src = sources[currentIndexRef.current];
        video.load();
    }, [src, fadeIn]);

    useEffect(() => {
        const video = videoRef.current;
        if (!video) return;

        const sources = Array.isArray(src) ? src : [src];
        video.src = sources[0];
        video.load();

        const handleLoadedData = () => {
            fadeIn(video);
        };

        const handleTimeUpdate = () => {
            if (!video.duration) return;
            const remaining = video.duration - video.currentTime;
            if (remaining <= 0.55) {
                fadeOut(video);
            }
        };

        const handleEnded = () => {
            advanceSource();
        };

        video.addEventListener('loadeddata', handleLoadedData);
        video.addEventListener('timeupdate', handleTimeUpdate);
        video.addEventListener('ended', handleEnded);

        return () => {
            video.removeEventListener('loadeddata', handleLoadedData);
            video.removeEventListener('timeupdate', handleTimeUpdate);
            video.removeEventListener('ended', handleEnded);
        };
    }, [src, fadeIn, fadeOut, advanceSource]);

    return (
        <video
            ref={videoRef}
            autoPlay
            muted
            playsInline
            preload="auto"
            className={className}
            style={{ opacity: 0, ...style }}
        />
    );
}
