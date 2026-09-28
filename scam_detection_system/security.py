"""
security.py - Simple in-memory rate limiting.

Two limits are used by the application:
    * Login attempts:  after LOGIN_MAX_ATTEMPTS failed logins from one IP
      address within LOGIN_LOCKOUT_MINUTES, further attempts are refused
      until the window has passed (slows down password guessing).
    * Analyses:        at most ANALYSIS_RATE_LIMIT_PER_MINUTE analyses per
      IP address per minute (protects the server from being flooded).

How it works (a "sliding window"): for every key (an IP address) we keep
the times of recent events. Events older than the window are discarded;
if the number of remaining events has reached the limit, the key is
limited.

Limitations (acceptable for a first version, documented in the README):
    * Counts are kept in memory, so they reset when the server restarts and
      are not shared between several server processes.
    * request.remote_addr is used as the IP. Behind a reverse proxy, use
      Werkzeug's ProxyFix so the real client address is seen.
"""

import threading
import time
from collections import defaultdict, deque


class RateLimiter:
    def __init__(self, max_events, window_seconds):
        self.max_events = max_events
        self.window_seconds = window_seconds
        self._events = defaultdict(deque)
        self._lock = threading.Lock()      # the dev server handles requests in threads

    def _prune(self, key, now):
        events = self._events[key]
        while events and now - events[0] >= self.window_seconds:
            events.popleft()
        if not events:
            del self._events[key]          # keep memory use small
            return deque()
        return events

    def is_limited(self, key):
        """True if the key has reached the limit (does not record an event)."""
        with self._lock:
            return len(self._prune(key, time.monotonic())) >= self.max_events

    def record(self, key):
        """Record one event for the key."""
        with self._lock:
            self._events[key].append(time.monotonic())

    def hit(self, key):
        """Record an event if allowed. Returns True if allowed, False if limited."""
        with self._lock:
            now = time.monotonic()
            if len(self._prune(key, now)) >= self.max_events:
                return False
            self._events[key].append(now)
            return True

    def retry_after(self, key):
        """Seconds until the key is allowed again (0 if not limited)."""
        with self._lock:
            now = time.monotonic()
            events = self._prune(key, now)
            if len(events) < self.max_events:
                return 0
            return max(int(self.window_seconds - (now - events[0])) + 1, 1)

    def reset(self, key):
        with self._lock:
            self._events.pop(key, None)
