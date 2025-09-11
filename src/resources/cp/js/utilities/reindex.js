/**
 * Search w/Elastic plugin for Craft CMS 4.x
 *
 * Provides high-performance search across all content types with real-time
 * indexing, advanced querying, and production reliability.
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

(
    function ($) {
      /**
       * Search w/Elastic Reindex Utility
       *
       * Manages the user interface and processing for reindexing Elasticsearch content.
       * Provides real-time progress tracking, error handling, and batch processing
       * with concurrent action management. Displays detailed statistics including
       * processing speed, ETA, failures, and partial indexing results.
       */
      Craft.SearchWithElasticUtility = Garnish.Base.extend({
        // UI Elements
        $trigger:          null, // Submit button element
        $form:             null, // Main form element
        $innerProgressBar: null, // Progress bar element

        // Action Tracking
        formAction:       null, // AJAX endpoint URL
        totalActions:     null, // Total number of actions to process
        completedActions: null, // Number of completed actions
        failedActions:    null, // Number of failed actions
        partialActions:   null, // Number of partially indexed actions
        loadingActions:   null, // Number of currently processing actions
        queue:            null, // Queue of pending action batches
        failedEntries:    null, // Array of failed entry details
        partialEntries:   null, // Array of partial entry details

        // Performance Tracking
        startTime:        null, // Processing start timestamp
        lastUpdateTime:   null, // Last speed calculation timestamp
        processedSinceLastUpdate: null, // Items processed since last speed update
        speedSamples:     null, // Rolling array of processing speed samples

        /**
         * Initialize the reindex utility interface
         * Sets up form elements, progress tracking, and event listeners
         * @param {string} formId - ID of the reindex form element
         */
        init: function (formId) {
          this.$form = $('#' + formId);
          this.$trigger = $('input.submit', this.$form);
          this.$status = $('.utility-status', this.$form);
          this.$failedContainer = $('.failed-entries-container', this.$form);
          this.formAction = this.$form.attr('action');
          // Store form action for AJAX requests

          // Create simple progress status text below the progress bar
          this.$progressStatus = $('<div class="progress-status-text" style="font-size: 12px; color: #666; margin-top: 8px; text-align: center;"></div>');
          this.$status.after(this.$progressStatus);

          this.addListener(this.$form, 'submit', this.onSubmit);
        },

        /**
         * Handle form submission to start reindexing process
         * Initializes progress tracking and begins first action
         * @param {Event} ev - Form submit event
         */
        onSubmit: function (ev) {
          ev.preventDefault();
          ev.stopPropagation();

          if (!this.$trigger.hasClass('disabled')) {
            if (!this.progressBar) {
              this.progressBar = new Craft.ProgressBar(this.$status);
            } else {
              this.progressBar.resetProgressBar();
            }

            this.totalActions = 1;
            this.completedActions = 0;
            this.failedActions = 0;
            this.partialActions = 0;
            this.failedEntries = [];
            this.partialEntries = [];
            this.queue = [];
            this.startTime = Date.now();
            this.lastUpdateTime = Date.now();
            this.processedSinceLastUpdate = 0;
            this.speedSamples = [];
            this.expandedGroups = {}; // Track which detail sections are expanded

            this.loadingActions = 0;
            this.currentEntryQueue = [];

            // Reset error display from previous runs
            this.$failedContainer.empty().hide();

            // Hide progress status until indexing begins
            this.$progressStatus.text('').hide();

            this.progressBar.$progressBar.css({
              top: Math.round(this.$status.outerHeight() / 2) - 6,
            }).removeClass('hidden');

            this.progressBar.$progressBar.velocity('stop').velocity(
                {
                  opacity: 1,
                },
                {
                  complete: $.proxy(function () {
                    var postData = Garnish.getPostData(this.$form);
                    var params = Craft.expandPostArray(postData);
                    params.start = true;

                    this.loadAction({
                      params: params,
                    });
                  }, this),
                },
            );

            if (this.$allDone) {
              this.$allDone.css('opacity', 0);
            }

            this.$trigger.addClass('disabled');
            this.$trigger.trigger('blur');
          }
        },

        /**
         * Update the visual progress bar based on completion percentage
         * Calculates width from completed vs total actions
         */
        updateProgressBar: function () {
          var width = (
              100 * this.completedActions / this.totalActions
          );
          this.progressBar.setProgressPercentage(width);
          this.updateProgressStatus();
        },

        /**
         * Updates the progress status display with current indexing statistics
         * Shows processed count, failures, warnings, processing speed, and ETA
         */
        updateProgressStatus: function() {
          var currentTime = Date.now();
          var totalProcessed = this.completedActions - 1; // Subtract initial action
          var remaining = this.totalActions - this.completedActions;

          // Calculate processing speed only after initial progress
          if (totalProcessed > 0) {
            this.processedSinceLastUpdate++;
            var timeSinceLastUpdate = currentTime - this.lastUpdateTime;

            if (timeSinceLastUpdate >= 1000 && this.processedSinceLastUpdate > 0) { // Update speed calculation every second
              var currentSpeed = this.processedSinceLastUpdate / (timeSinceLastUpdate / 1000);
              this.speedSamples.push(currentSpeed);

              // Maintain rolling average of last 5 speed samples
              if (this.speedSamples.length > 5) {
                this.speedSamples.shift();
              }

              this.lastUpdateTime = currentTime;
              this.processedSinceLastUpdate = 0;
            }
          }

          // Calculate average processing speed from samples
          var avgSpeed = 0;
          if (this.speedSamples.length > 0) {
            avgSpeed = this.speedSamples.reduce(function(a, b) { return a + b; }) / this.speedSamples.length;
          } else if (totalProcessed > 0) {
            // Use overall average if no recent samples available
            var totalTime = (currentTime - this.startTime) / 1000;
            if (totalTime > 0) {
              avgSpeed = totalProcessed / totalTime;
            }
          }

          // Calculate estimated time to completion
          var eta = '';
          if (avgSpeed > 0 && remaining > 0) {
            var etaSeconds = Math.ceil(remaining / avgSpeed);
            eta = this.formatTime(etaSeconds);
          }

          // Construct progress status message with statistics
          var statusParts = [];

          if (this.failedActions > 0) {
            statusParts.push(this.failedActions + ' failures');
          }
          if (this.partialActions > 0) {
            statusParts.push(this.partialActions + ' warnings');
          }

          var statusText = totalProcessed + ' / ' + (this.totalActions - 1);
          if (statusParts.length > 0) {
            statusText += ' (' + statusParts.join(', ') + ')';
          }

          // Include speed and ETA after initial processing
          if (avgSpeed > 0 && totalProcessed > 2) { // Only show speed after a few elements
            statusText += ' • ' + Math.round(avgSpeed) + '/s';
            if (eta && remaining > 1) { // Only show ETA if more than 1 item remaining
              statusText += ' • ETA ' + eta;
            }
          }

          this.$progressStatus.text(statusText).show();
        },

        /**
         * Formats elapsed time in seconds to human-readable format with precision
         * @param {number} seconds - Total elapsed time in seconds
         * @returns {string} Formatted time string (e.g., "45.2s", "2m 30s", "1h 5m 12s")
         */
        formatElapsedTime: function(seconds) {
          if (seconds < 60) {
            // Show decimal for times under a minute
            return seconds.toFixed(1) + 's';
          } else if (seconds < 3600) {
            var minutes = Math.floor(seconds / 60);
            var secs = Math.round(seconds % 60);
            return minutes + 'm' + (secs > 0 ? ' ' + secs + 's' : '');
          } else {
            var hours = Math.floor(seconds / 3600);
            var minutes = Math.floor((seconds % 3600) / 60);
            var secs = Math.round(seconds % 60);
            var result = hours + 'h';
            if (minutes > 0) result += ' ' + minutes + 'm';
            if (secs > 0) result += ' ' + secs + 's';
            return result;
          }
        },
        
        /**
         * Formats time duration in seconds to human-readable format
         * @param {number} seconds - Duration in seconds
         * @returns {string} Formatted time string (e.g., "2m 30s", "1h 5m")
         */
        formatTime: function(seconds) {
          if (seconds < 60) {
            return seconds + 's';
          } else if (seconds < 3600) {
            var minutes = Math.floor(seconds / 60);
            var secs = seconds % 60;
            return minutes + 'm' + (secs > 0 ? ' ' + secs + 's' : '');
          } else {
            var hours = Math.floor(seconds / 3600);
            var minutes = Math.floor((seconds % 3600) / 60);
            return hours + 'h' + (minutes > 0 ? ' ' + minutes + 'm' : '');
          }
        },

        /**
         * Load and execute an indexing action
         * @param {Object} data - Action data containing parameters
         */
        loadAction: function (data) {
          this.loadingActions++;
          this.postActionRequest(data.params);
        },

        /**
         * Send AJAX request to perform indexing action
         * @param {Object} params - Request parameters for indexing
         */
        postActionRequest: function (params) {
          var data = {
            params: params,
          };

          Craft.postActionRequest(
              this.formAction,
              data,
              $.proxy(this, 'onActionResponse'),
              {
                complete: $.noop,
              },
          );
        },

        /**
         * Handle response from indexing action
         * Updates progress, handles errors, and manages batching
         * @param {Object} response - Server response data
         * @param {string} textStatus - HTTP status text
         */
        onActionResponse: function (response, textStatus) {
          this.loadingActions--;
          this.completedActions++;

          // Process response and add new entries to indexing queue
          if (textStatus === 'success' && response && response.entries) {
            for (var i = 0; i < response.entries.length; i++) {
              if (response.entries[ i ].length) {
                this.totalActions += response.entries[ i ].length;
                this.queue.push(response.entries[ i ]);
              }
            }
          }

          // Track indexing errors for display in completion summary
          if (response && response.error) {
            this.failedActions++;
            var errorEntry = {
              error: response.error,
              errorDetails: response.errorDetails || '',
              elementId: response.elementId || 'Unknown',
              elementType: response.elementType || 'Unknown'
            };
            this.failedEntries.push(errorEntry);
            
            // Display error immediately in live container
            this.displayLiveError(errorEntry);
          }

          // Track partial indexing results (basic metadata only)
          if (response && response.partial) {
            this.partialActions++;
            var partialEntry = {
              reason: response.reason,
              elementId: response.elementId || 'Unknown',
              elementType: response.elementType || 'Unknown'
            };
            this.partialEntries.push(partialEntry);
            
            // Display warning immediately in live container
            this.displayLiveWarning(partialEntry);
          }

          this.updateProgressBar();

          // Process next batch items up to concurrency limit
          while (this.loadingActions < Craft.SearchWithElasticUtility.maxConcurrentActions &&
                 this.currentEntryQueue.length) {
            this.loadNextAction();
          }

          // Check if current batch is complete
          if (!this.loadingActions) {
            // Start next batch if available
            if (this.queue.length > 0) {
              this.currentEntryQueue = this.queue.shift();
              this.loadNextAction();
            } else {
              // Brief delay to prevent jarring UI transitions
              setTimeout($.proxy(this, 'onComplete'), 300);
            }
          }
        },

        /**
         * Load the next action from the current entry queue
         * Removes first item from queue and processes it
         */
        loadNextAction: function () {
          var data = this.currentEntryQueue.shift();
          this.loadAction(data);
        },

        /**
         * Render grouped errors and warnings
         * Used for both live updates and final summary
         * @param {boolean} isLive - Whether this is a live update during processing
         */
        renderGroupedIssues: function(isLive) {
          // Clear container for final display (keep live display during processing)
          if (!isLive) {
            this.$failedContainer.empty();
          }
          
          var hasContent = false;
          
          // Render errors section
          if (this.failedEntries.length > 0) {
            hasContent = true;
            var $errorSection = this.createGroupedSection(
              this.failedEntries, 
              'error', 
              'Indexing Errors', 
              'The following elements failed to index:',
              function(entry) { return entry.errorDetails || entry.error || 'Unknown error'; },
              isLive
            );
            
            if (isLive) {
              // For live display, replace or add the live errors section
              var $existingLive = this.$failedContainer.find('.live-errors');
              if ($existingLive.length) {
                $existingLive.replaceWith($errorSection.addClass('live-errors'));
              } else {
                this.$failedContainer.prepend($errorSection.addClass('live-errors'));
              }
            } else {
              this.$failedContainer.append($errorSection);
            }
          }
          
          // Render warnings section
          if (this.partialEntries.length > 0) {
            hasContent = true;
            var $warningSection = this.createGroupedSection(
              this.partialEntries, 
              'warning', 
              'Indexing Warnings', 
              'The following elements were partially indexed (metadata only):',
              function(entry) { return entry.reason || 'Unknown reason'; },
              isLive
            );
            
            if (isLive) {
              // For live display, replace or add the live warnings section
              var $existingLive = this.$failedContainer.find('.live-warnings');
              if ($existingLive.length) {
                $existingLive.replaceWith($warningSection.addClass('live-warnings'));
              } else {
                // Add after errors if they exist, otherwise prepend
                var $liveErrors = this.$failedContainer.find('.live-errors');
                if ($liveErrors.length) {
                  $liveErrors.after($warningSection.addClass('live-warnings'));
                } else {
                  this.$failedContainer.prepend($warningSection.addClass('live-warnings'));
                }
              }
            } else {
              this.$failedContainer.append($warningSection);
            }
          }
          
          if (hasContent) {
            this.$failedContainer.show();
          } else {
            this.$failedContainer.hide();
          }
        },
        
        /**
         * Create a grouped section for errors or warnings
         * @param {Array} entries - Array of error/warning entries
         * @param {string} type - 'error' or 'warning'
         * @param {string} title - Section title
         * @param {string} description - Section description
         * @param {Function} getKey - Function to extract grouping key from entry
         * @param {boolean} isLive - Whether this is a live update
         * @returns {jQuery} The section element
         */
        createGroupedSection: function(entries, type, title, description, getKey, isLive) {
          // Group entries by message
          var groups = {};
          for (var i = 0; i < entries.length; i++) {
            var entry = entries[i];
            var key = getKey(entry);
            if (!groups[key]) {
              groups[key] = [];
            }
            groups[key].push(entry);
          }
          
          // Create section
          var $section = $('<div class="notice ' + type + '" style="margin-bottom: 16px;">');
          var titleText = title + ' (' + entries.length + ')';
          if (isLive) {
            titleText += ' - Pending';
          }
          var $title = $('<h3>').text(titleText);
          var $description = $('<p class="light">').text(description);
          var $list = $('<ul>');
          
          // Render grouped items
          for (var message in groups) {
            var elements = groups[message];
            var groupKey = type + ':' + this.hashCode(message);
            
            if (elements.length > 3) {
              // Group identical messages
              var elementIds = elements.map(function(e) { return e.elementId; });
              var elementTypes = {};
              elements.forEach(function(e) {
                elementTypes[e.elementType] = (elementTypes[e.elementType] || 0) + 1;
              });
              
              var typesSummary = Object.keys(elementTypes).map(function(type) {
                return elementTypes[type] + ' ' + type + (elementTypes[type] > 1 ? 's' : '');
              }).join(', ');
              
              var $item = $('<li style="margin-bottom: 12px;" data-item-key="' + groupKey + '">');
              var itemHtml = '<div class="element-count"><strong>' + elements.length + ' elements</strong> <span class="light">(' + typesSummary + ')</span></div>';
              itemHtml += '<div style="font-size: 0.9em;">' + this.escapeHtml(message) + '</div>';
              itemHtml += '<details data-group-key="' + groupKey + '" open style="margin-top: 4px;"><summary style="cursor: pointer; font-size: 0.9em;">Element IDs</summary>';
              itemHtml += '<span class="element-ids" style="font-size: 0.85em; color: #666;">' + elementIds.join(', ') + '</span></details>';
              $item.html(itemHtml);
              $list.append($item);
            } else {
              // Show individual items
              for (var j = 0; j < elements.length; j++) {
                var entry = elements[j];
                var $item = $('<li style="margin-bottom: 8px;" data-item-key="' + groupKey + '-' + entry.elementId + '">').html(
                  '<strong>Element ID ' + entry.elementId + '</strong> <span class="light">(' + entry.elementType + ')</span><br>' +
                  '<span style="font-size: 0.9em;">' + this.escapeHtml(message) + '</span>'
                );
                $list.append($item);
              }
            }
          }
          
          $section.append($title).append($description).append($list);
          return $section;
        },
        
        /**
         * Display error in real-time as it happens
         * @param {Object} errorEntry - Error details object
         */
        displayLiveError: function(errorEntry) {
          // Just re-render everything with the new data
          this.renderGroupedIssues(true);
        },
        
        /**
         * Display warning in real-time as it happens
         * @param {Object} partialEntry - Warning details object
         */
        displayLiveWarning: function(partialEntry) {
          // Just re-render everything with the new data
          this.renderGroupedIssues(true);
        },
        
        /**
         * Simple hash code generator for creating unique keys
         * @param {string} str - String to hash
         * @returns {number} Hash code
         */
        hashCode: function(str) {
          var hash = 0;
          if (!str || str.length === 0) return hash;
          for (var i = 0; i < str.length; i++) {
            var char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
          }
          return Math.abs(hash);
        },
        
        /**
         * Escape HTML to prevent XSS
         * @param {string} text - Text to escape
         * @returns {string} Escaped HTML
         */
        escapeHtml: function(text) {
          var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
          };
          return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        },
        
        /**
         * Handle completion of all indexing actions
         * Shows completion status and any error/warning summaries
         */
        onComplete: function () {
          // Calculate total time taken
          var totalTime = (Date.now() - this.startTime) / 1000;
          var timeDisplay = this.formatElapsedTime(totalTime);
          // Set completion icon based on indexing results
          var hasFailures = this.failedActions > 0;
          var hasPartials = this.partialActions > 0;
          var icon = hasFailures ? 'alert' : (hasPartials ? 'info' : 'done');

          if (!this.$allDone) {
            this.$allDone = $('<div class="alldone" data-icon="' + icon + '" />').appendTo(this.$status);
            this.$allDone.css('opacity', 0);
          } else {
            this.$allDone.attr('data-icon', icon);
          }

          // Display partial indexing warnings before errors
          if (hasPartials) {
            this.displayPartialEntries();
          }

          // Display failed indexing errors after warnings
          if (hasFailures) {
            this.displayFailedEntries();
          }

          this.progressBar.$progressBar.velocity({ opacity: 0 }, {
            duration: 'fast', complete: $.proxy(function () {
              this.$allDone.velocity({ opacity: 1 }, { duration: 'fast' });
              
              // Update status to show completion time instead of hiding
              var totalProcessed = this.totalActions - 1;
              var statusText = 'Completed ' + totalProcessed + ' elements in ' + timeDisplay;
              
              if (this.failedActions > 0) {
                statusText += ' • ' + this.failedActions + ' failed';
              }
              if (this.partialActions > 0) {
                statusText += ' • ' + this.partialActions + ' warnings';
              }
              
              // Use Craft's built-in classes for proper theming
              var statusClass = hasFailures ? 'error' : (hasPartials ? 'warning' : 'success');
              this.$progressStatus.html('<span class="' + statusClass + '" style="font-weight: bold;">' + statusText + '</span>');
              
              this.$trigger.removeClass('disabled');
              this.$trigger.trigger('focus');
            }, this),
          });
        },

        /**
         * Display error summary for elements that failed to index
         */
        displayFailedEntries: function () {
          // Just use the unified renderer for final display
          this.renderGroupedIssues(false);
        },

        /**
         * Display warning summary for partially indexed elements
         */
        displayPartialEntries: function () {
          // The unified renderer handles both warnings and errors
          // This is called first, so just let it render
          if (this.partialEntries.length > 0 || this.failedEntries.length > 0) {
            this.renderGroupedIssues(false);
          }
        },
      }, {
        maxConcurrentActions: 3,
      });
    }
)(jQuery);
