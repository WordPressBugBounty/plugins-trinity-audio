async function trinityMetaVoiceConfig() {
  const {dispatch} = wp.data;
  const editorDispatch = dispatch('core/editor');

  // Save original savePost
  const originalSavePost = editorDispatch.savePost;

  let originalConfig;
  waitForExpression(() => window.TRINITY_UNIT_CONFIGURATION.getFormData).then(async () => {
    originalConfig = await window.TRINITY_UNIT_CONFIGURATION.getFormData();
  });

  // Replace savePost with our wrapper
  editorDispatch.savePost = async (...args) => {
    const formData = await window.TRINITY_UNIT_CONFIGURATION.getFormData();

    if (originalConfig && JSON.stringify(originalConfig) !== JSON.stringify(formData)) {
      const voiceIdInputEl = document.getElementById('trinity_audio_voice_id');
      voiceIdInputEl.value = formData.voiceId; // set public voiceId

      // Good to save locale and not only voiceId, since if voiceId get removed, we have locale which we can rely on
      const languageInputEl = document.getElementById('trinity_audio_source_language');
      languageInputEl.value = formData.code;
    }

    // Continue with original save
    return originalSavePost(...args);
  };
}

function trinitySendMetricMeta(metric, additionalData) {
  $.ajax({
    type: 'POST',
    url: ajaxurl,
    data: {
      metric,
      additionalData,
      action: window.TRINITY_WP_ADMIN.TRINITY_AUDIO_SEND_METRIC
    }
  });
}

function waitForExpression(expressionFn) {
  return new Promise((resolve) => {
    const t = setInterval(() => {
      if (!!expressionFn()) {
        resolve();
        clearInterval(t);
      }
    }, 1000);
  });
}

(function ($) {
  const ERROR_GET_VALUE = 'Unable to retrieve value';

  const submitButton = $('#trinity-metabox .components-tab-panel__tab-content .content[data-id="advanced"] button');

  function initTabPanel() {
    const tabs = $('#trinity-metabox .components-tab-panel__tabs');
    tabs.click(function (e) {
      const target = e.target;
      const id = target.dataset.id;

      if (!id) return;

      $('#trinity-metabox .components-tab-panel__tabs button').removeClass('is-active');
      $(e.target).addClass('is-active');

      $('#trinity-metabox .components-tab-panel__tab-content .content').removeClass('is-active');
      $(`#trinity-metabox .components-tab-panel__tab-content .content[data-id='${id}']`).addClass('is-active');
    });

    submitButton.click(function () {
      regenerateTokens(window.TRINITY_WP_METABOX.postId);
    });
  }

  function regenerateTokens(postId) {
    const id = '#trinity-metabox';

    $.ajax({
      type: 'POST',
      url: ajaxurl,
      data: {
        action: window.TRINITY_WP_ADMIN.TRINITY_AUDIO_REGENERATE_TOKENS,
        post_id: postId
      },
      dataType: 'json',
      beforeSend: function () {
        submitButton.prop('disabled', true);
        trinityShowStatus(id, 'progress');
      },
      complete: function () {
        submitButton.prop('disabled', false);
      },
      success: function (response) {
        if (!response || response.error) return trinityShowStatus(id, 'error');

        // update token labels
        const postMetaMap = window.TRINITY_WP_METABOX.TRINITY_AUDIO_POST_META_MAP;
        const titleContent = response[postMetaMap.title_content];

        $('.trinity-meta-title-content').text(titleContent || ERROR_GET_VALUE);

        if (!titleContent) return trinityShowStatus(id, 'error');

        trinityShowStatus(id, 'success');
      }
    }).fail(function (response) {
      console.error('TRINITY_WP', response);
      trinityShowStatus(id, 'error');
    });
  }

  initTabPanel();
})(jQuery);
