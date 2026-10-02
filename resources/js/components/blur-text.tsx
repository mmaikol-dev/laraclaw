import { useEffect, useRef, useState } from 'react';
import { motion } from 'framer-motion';

interface BlurTextProps {
    text: string;
    className?: string;
}

export default function BlurText({ text, className = '' }: BlurTextProps) {
    const words = text.split(' ');
    const [isVisible, setIsVisible] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const node = containerRef.current;
        if (!node) return;

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setIsVisible(true);
                    observer.disconnect();
                }
            },
            { threshold: 0.1 },
        );

        observer.observe(node);
        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={containerRef}
            className={className}
            style={{
                display: 'flex',
                flexWrap: 'wrap',
                justifyContent: 'center',
                rowGap: '0.1em',
            }}
        >
            {words.map((word, i) => (
                <motion.span
                    key={`${word}-${i}`}
                    style={{ display: 'inline-block', marginRight: '0.28em' }}
                    initial={{ filter: 'blur(10px)', opacity: 0, y: 50 }}
                    animate={
                        isVisible
                            ? {
                                  filter: 'blur(0px)',
                                  opacity: 1,
                                  y: 0,
                              }
                            : undefined
                    }
                    transition={{
                        duration: 0.7,
                        delay: i * 0.1,
                        ease: 'easeOut',
                    }}
                >
                    {word}
                </motion.span>
            ))}
        </div>
    );
}
