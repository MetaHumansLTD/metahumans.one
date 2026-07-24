/**
 * Storage Utility - Handles localStorage operations
 * Provides methods for storing, retrieving, and managing data in browser storage
 */

class StorageManager {
  constructor(prefix = 'app_') {
    this.prefix = prefix;
  }

  /**
   * Store data in localStorage
   * @param {string} key - Storage key
   * @param {any} value - Value to store
   * @returns {boolean} - Success status
   */
  set(key, value) {
    try {
      const serializedValue = JSON.stringify(value);
      localStorage.setItem(this.prefix + key, serializedValue);
      return true;
    } catch (error) {
      console.error('Storage set error:', error);
      return false;
    }
  }

  /**
   * Retrieve data from localStorage
   * @param {string} key - Storage key
   * @param {any} defaultValue - Default value if key doesn't exist
   * @returns {any} - Retrieved value or default
   */
  get(key, defaultValue = null) {
    try {
      const item = localStorage.getItem(this.prefix + key);
      return item ? JSON.parse(item) : defaultValue;
    } catch (error) {
      console.error('Storage get error:', error);
      return defaultValue;
    }
  }

  /**
   * Remove item from localStorage
   * @param {string} key - Storage key
   * @returns {boolean} - Success status
   */
  remove(key) {
    try {
      localStorage.removeItem(this.prefix + key);
      return true;
    } catch (error) {
      console.error('Storage remove error:', error);
      return false;
    }
  }

  /**
   * Clear all items with the current prefix
   * @returns {boolean} - Success status
   */
  clear() {
    try {
      const keys = Object.keys(localStorage);
      keys.forEach(key => {
        if (key.startsWith(this.prefix)) {
          localStorage.removeItem(key);
        }
      });
      return true;
    } catch (error) {
      console.error('Storage clear error:', error);
      return false;
    }
  }

  /**
   * Check if key exists in storage
   * @param {string} key - Storage key
   * @returns {boolean} - Existence status
   */
  exists(key) {
    return localStorage.getItem(this.prefix + key) !== null;
  }

  /**
   * Get all keys with current prefix
   * @returns {string[]} - Array of keys
   */
  getAllKeys() {
    const keys = Object.keys(localStorage);
    return keys
      .filter(key => key.startsWith(this.prefix))
      .map(key => key.substring(this.prefix.length));
  }

  /**
   * Get storage size in bytes (approximate)
   * @returns {number} - Size in bytes
   */
  getSize() {
    let total = 0;
    for (let key in localStorage) {
      if (key.startsWith(this.prefix)) {
        total += localStorage[key].length + key.length;
      }
    }
    return total;
  }
}

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
  module.exports = StorageManager;
} else {
  window.StorageManager = StorageManager;
}

// Example usage:
// const storage = new StorageManager('myApp_');
// storage.set('user', { name: 'John', age: 30 });
// const user = storage.get('user');
// storage.remove('user');
// storage.clear();