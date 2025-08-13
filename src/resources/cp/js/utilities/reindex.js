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
            this.failedEntries.push({
              error: response.error,
              elementId: response.elementId || 'Unknown',
              elementType: response.elementType || 'Unknown'
            });
          }

          // Track partial indexing results (basic metadata only)
          if (response && response.partial) {
            this.partialActions++;
            this.partialEntries.push({
              reason: response.reason,
              elementId: response.elementId || 'Unknown',
              elementType: response.elementType || 'Unknown'
            });
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
         * Handle completion of all indexing actions
         * Shows completion status and any error/warning summaries
         */
        onComplete: function () {
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
              this.$progressStatus.hide(); // Hide status when complete
              this.$trigger.removeClass('disabled');
              this.$trigger.trigger('focus');
            }, this),
          });
        },

        /**
         * Display error summary for elements that failed to index
         * Creates error notice with details of failed elements
         */
        displayFailedEntries: function () {
          if (this.failedEntries.length === 0) {
            return;
          }

          // Add visual separator between warning and error sections
          if (this.partialEntries.length > 0) {
            var $separator = $('<hr style="margin: 20px 0; border: none; border-top: 1px solid #e3e5e8;">');
            this.$failedContainer.append($separator);
          }

          // Create error notice using Craft's standard styling
          var $errorNotice = $('<div class="notice error">');
          var $title = $('<h3>').text('Failed to Index (' + this.failedEntries.length + ')');
          var $list = $('<ul class="failed-entries-list">');
          var $description = $('<p class="light">').text('These elements could not be indexed and will not appear in search results.');

          for (var i = 0; i < this.failedEntries.length; i++) {
            var entry = this.failedEntries[i];
            var $item = $('<li style="margin-bottom: 8px;">').html(
              '<strong>Element ID ' + entry.elementId + '</strong> <span class="light">(' + entry.elementType + ')</span><br>' +
              '<span class="error-message" style="font-size: 0.9em; color: #a94442;">' + entry.error + '</span>'
            );
            $list.append($item);
          }

          $errorNotice.append($title).append($description).append($list);
          this.$failedContainer.append($errorNotice);
        },

        /**
         * Display warning summary for partially indexed elements
         * Creates warning notice for elements indexed with basic metadata only
         */
        displayPartialEntries: function () {
          if (this.partialEntries.length === 0) {
            return;
          }

          // Create warning notice using Craft's standard styling
          var $warningNotice = $('<div class="notice warning" style="margin-bottom: 20px;">');
          var $title = $('<h3>').text('Partially Indexed Elements (' + this.partialEntries.length + ')');
          var $list = $('<ul class="partial-entries-list">');
          var $description = $('<p class="light">').text('These elements were indexed with basic metadata, but content fetching failed. They are still searchable.');

          for (var i = 0; i < this.partialEntries.length; i++) {
            var entry = this.partialEntries[i];
            var $item = $('<li style="margin-bottom: 8px;">').html(
              '<strong>Element ID ' + entry.elementId + '</strong> <span class="light">(' + entry.elementType + ')</span><br>' +
              '<span class="warning-message" style="font-size: 0.9em; color: #8a6d3b;">' + entry.reason + '</span>'
            );
            $list.append($item);
          }

          $warningNotice.append($title).append($description).append($list);
          this.$failedContainer.append($warningNotice).show();
        },
      }, {
        maxConcurrentActions: 3,
      });
    }
)(jQuery);
