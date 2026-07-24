/**
 * Memory Manager - Handles in-memory data storage and caching
 * Provides methods for managing temporary data, caching, and memory optimization
 */

class MemoryManager {
  constructor(maxSize = 100) {
    this.cache = new Map();
    this.maxSize = maxSize;
    this.accessTimes = new Map();
    this.expirationTimes = new Map();
  }

  /**
   * Store data in memory cache
   * @param {string} key - Cache key
   * @param {any} value - Value to cache
   * @param {number} ttl - Time to live in milliseconds (optional)
   * @returns {boolean} - Success status
   */
  set(key, value, ttl = null) {
    try {
      // Remove expired items first
      this.cleanup();

      // If cache is full, remove least recently used item
      if (this.cache.size >= this.maxSize && !this.cache.has(key)) {
        this.removeLRU();
      }

      this.cache.set(key, value);
      this.accessTimes.set(key, Date.now());

      // Set expiration time if TTL is provided
      if (ttl) {
        this.expirationTimes.set(key, Date.now() + ttl);
      }

      return true;
    } catch (error) {
      console.error('Memory set error:', error);
      return false;
    }
  }

  /**
   * Retrieve data from memory cache
   * @param {string} key - Cache key
   * @param {any} defaultValue - Default value if key doesn't exist
   * @returns {any} - Retrieved value or default
   */
  get(key, defaultValue = null) {
    try {
      // Check if item has expired
      if (this.isExpired(key)) {
        this.remove(key);
        return defaultValue;
      }

      if (this.cache.has(key)) {
        // Update access time for LRU
        this.accessTimes.set(key, Date.now());
        return this.cache.get(key);
      }

      return defaultValue;
    } catch (error) {
      console.error('Memory get error:', error);
      return defaultValue;
    }
  }

  /**
   * Remove item from memory cache
   * @param {string} key - Cache key
   * @returns {boolean} - Success status
   */
  remove(key) {
    try {
      this.cache.delete(key);
      this.accessTimes.delete(key);
      this.expirationTimes.delete(key);
      return true;
    } catch (error) {
      console.error('Memory remove error:', error);
      return false;
    }
  }

  /**
   * Clear all cached data
   * @returns {boolean} - Success status
   */
  clear() {
    try {
      this.cache.clear();
      this.accessTimes.clear();
      this.expirationTimes.clear();
      return true;
    } catch (error) {
      console.error('Memory clear error:', error);
      return false;
    }
  }

  /**
   * Check if key exists in cache
   * @param {string} key - Cache key
   * @returns {boolean} - Existence status
   */
  has(key) {
    if (this.isExpired(key)) {
      this.remove(key);
      return false;
    }
    return this.cache.has(key);
  }

  /**
   * Get all cache keys
   * @returns {string[]} - Array of keys
   */
  keys() {
    this.cleanup();
    return Array.from(this.cache.keys());
  }

  /**
   * Get cache size
   * @returns {number} - Number of cached items
   */
  size() {
    this.cleanup();
    return this.cache.size;
  }

  /**
   * Check if an item has expired
   * @param {string} key - Cache key
   * @returns {boolean} - Expiration status
   */
  isExpired(key) {
    const expirationTime = this.expirationTimes.get(key);
    return expirationTime && Date.now() > expirationTime;
  }

  /**
   * Remove least recently used item
   * @private
   */
  removeLRU() {
    let oldestKey = null;
    let oldestTime = Infinity;

    for (const [key, time] of this.accessTimes) {
      if (time < oldestTime) {
        oldestTime = time;
        oldestKey = key;
      }
    }

    if (oldestKey) {
      this.remove(oldestKey);
    }
  }

  /**
   * Remove expired items
   * @private
   */
  cleanup() {
    const now = Date.now();
    for (const [key, expirationTime] of this.expirationTimes) {
      if (now > expirationTime) {
        this.remove(key);
      }
    }
  }

  /**
   * Get memory usage statistics
   * @returns {object} - Memory statistics
   */
  getStats() {
    this.cleanup();
    return {
      size: this.cache.size,
      maxSize: this.maxSize,
      usage: (this.cache.size / this.maxSize * 100).toFixed(2) + '%',
      keys: this.keys()
    };
  }

  /**
   * Set cache with automatic cleanup interval
   * @param {number} interval - Cleanup interval in milliseconds
   */
  startAutoCleanup(interval = 60000) { // Default: 1 minute
    if (this.cleanupInterval) {
      clearInterval(this.cleanupInterval);
    }
    
    this.cleanupInterval = setInterval(() => {
      this.cleanup();
    }, interval);
  }

  /**
   * Stop automatic cleanup
   */
  stopAutoCleanup() {
    if (this.cleanupInterval) {
      clearInterval(this.cleanupInterval);
      this.cleanupInterval = null;
    }
  }
}

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
  module.exports = MemoryManager;
} else {
  window.MemoryManager = MemoryManager;
}

// Example usage:
// const memory = new MemoryManager(50);
// memory.set('user_data', { name: 'John' }, 5000); // Expires in 5 seconds
// const userData = memory.get('user_data');
// memory.startAutoCleanup(30000); // Cleanup every 30 seconds
// console.log(memory.getStats());