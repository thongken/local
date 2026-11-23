export const SAMPLE_RATE = 16000;
export const FRAME = 512;                 // 32ms @16k (Silero v5)
export const MAX_AUDIO_SEC = 30 * 60;
export const CHUNK = { minSec: 60, hardSec: 75, pauseSec: 0.5, onThreshold: 0.5, offThreshold: 0.35 };
export const SEGMENT_TARGET_SEC = 240;
export const LIMITS = { maxSegments: 30, maxQuestions: 30, maxTranscript: 300000, maxContent: 500000, maxDurationSec: 1830 };
export const DEFAULT_GEMINI_MODEL = 'gemini-3.5-flash-lite';
