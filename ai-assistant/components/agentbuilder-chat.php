<?php
/**
 * AgentBuilder ChatKit embed (global_admin only)
 * - Mounts the new Agent Builder workflow via ChatKit for testing
 * - Gated to signed-in global_admin users
 */

// Require active session and role
if (session_status() === PHP_SESSION_NONE) {
    session_name("logistics_session");
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    return; // not logged in → do not render
}

$user_role = $_SESSION['role'] ?? 'user';
if ($user_role !== 'global_admin') {
    return; // only render for global_admin
}

// Your AgentBuilder workflow ID
$agent_workflow_id = 'wf_68e947564cf08190bc63d9e7d8dce3e60aaed4595618cbef';

// Try to resolve OpenAI API key for ChatKit (testing only)
$openai_api_key = '';

// Prefer a config helper if available (production config.php)
// Silently ignore if not present
@include_once dirname(__DIR__, 2) . '/config.php';
if (function_exists('getOpenAIApiKey')) {
    try { $openai_api_key = (string) getOpenAIApiKey(); } catch (Throwable $e) { /* ignore */ }
}

// Fallbacks
if ($openai_api_key === '' && function_exists('getenv')) {
    $openai_api_key = getenv('OPENAI_API_KEY') ?: '';
}
if ($openai_api_key === '' && defined('OPENAI_API_KEY')) {
    $openai_api_key = (string) OPENAI_API_KEY;
}

?>

<div id="agentbuilder-chatkit-root" style="position: fixed; bottom: 20px; right: 20px; z-index: 2147483647;
  width: 380px; max-width: calc(100vw - 32px); height: 600px; display: none;"></div>

<script>
(function(){
  // Expose minimal context to ChatKit if you want to pass user details
  window.AgentBuilderContext = {
    userId: <?php echo json_encode($_SESSION['user_id']); ?>,
    username: <?php echo json_encode($_SESSION['username'] ?? 'Admin'); ?>,
    role: <?php echo json_encode($user_role); ?>
  };
})();
</script>

<script>
(function(){
  // Security note: This embeds the OpenAI API key client-side for testing only.
  // Do NOT use this approach in production without an authenticated token exchange.
  const OPENAI_API_KEY = <?php echo json_encode($openai_api_key); ?>;
  const WORKFLOW_ID = <?php echo json_encode($agent_workflow_id); ?>;

  // If API key is missing, show a gentle console warning and skip initialization
  if (!OPENAI_API_KEY) {
    console.warn('[AgentBuilder] OpenAI API key is missing; ChatKit will not initialize.');
    return;
  }

  function resolveGlobalChatKit() {
    var g = window;
    return (
      g.ChatKit ||
      (g.OpenAI && (g.OpenAI.ChatKit || g.OpenAI.Chatkit)) ||
      g.OpenAIChatKit ||
      g.chatkit ||
      g.Chatkit ||
      null
    );
  }

  function mountChatKitGlobal() {
    try {
      var GlobalCtor = resolveGlobalChatKit();
      if (!GlobalCtor) {
        console.error('[AgentBuilder] ChatKit global not found after script load.');
        return false;
      }
      var chatkit = new GlobalCtor({
        workflowId: WORKFLOW_ID,
        apiKey: OPENAI_API_KEY,
        theme: { brandColor: '#488C9A' }
      });
      var rootSelector = '#agentbuilder-chatkit-root';
      var root = document.querySelector(rootSelector);
      if (root) root.style.display = 'block';
      chatkit.mount(rootSelector);
      return true;
    } catch (err) {
      console.error('[AgentBuilder] Failed to initialize ChatKit (global):', err);
      return false;
    }
  }

  function mountChatKitModule() {
    // Fallback to ESM build via esm.sh
    var ms = document.createElement('script');
    ms.type = 'module';
    ms.textContent = `
      (async () => {
        try {
          const mod = await import('https://esm.sh/@openai/chatkit@latest');
          const ChatKit = mod.ChatKit || mod.default;
          if (!ChatKit) { console.error('[AgentBuilder] ChatKit export missing in module'); return; }
          const chatkit = new ChatKit({
            workflowId: <?php echo json_encode($agent_workflow_id); ?>,
            apiKey: <?php echo json_encode($openai_api_key); ?>,
            theme: { brandColor: '#488C9A' }
          });
          const rootSelector = '#agentbuilder-chatkit-root';
          const root = document.querySelector(rootSelector);
          if (root) root.style.display = 'block';
          chatkit.mount(rootSelector);
        } catch (e) {
          console.error('[AgentBuilder] ESM import failed:', e);
        }
      })();
    `;
    document.head.appendChild(ms);
  }

  function loadScript(url, onload, onerror){
    var s = document.createElement('script');
    s.src = url; s.async = true; s.crossOrigin = 'anonymous';
    s.onload = onload; s.onerror = onerror; document.head.appendChild(s);
  }

  // Preferred: ESM import from OpenAI platform CDN
  try {
    var esm = document.createElement('script');
    esm.type = 'module';
    esm.textContent = `
      (async () => {
        try {
          const mod = await import('https://cdn.platform.openai.com/deployments/chatkit/chatkit.js');
          const ChatKit = mod.ChatKit || mod.default || mod.chatkit || mod.Chatkit;
          if (!ChatKit) {
            try { console.debug('[AgentBuilder] Module exports:', Object.keys(mod)); } catch(e) {}
            console.error('[AgentBuilder] ChatKit export missing in module');
            return;
          }
          const chatkit = new ChatKit({
            workflowId: ${JSON.stringify($agent_workflow_id)},
            apiKey: ${JSON.stringify($openai_api_key)},
            theme: { brandColor: '#488C9A' }
          });
          const rootSelector = '#agentbuilder-chatkit-root';
          const root = document.querySelector(rootSelector);
          if (root) root.style.display = 'block';
          chatkit.mount(rootSelector);
        } catch (e) {
          console.error('[AgentBuilder] Platform CDN import error:', e);
        }
      })();
    `;
    esm.onerror = function(){
      console.warn('[AgentBuilder] ESM import from platform CDN failed; trying global builds...');
      // Fallback chain: jsDelivr → unpkg → ESM via esm.sh
      loadScript(
        'https://cdn.jsdelivr.net/npm/@openai/chatkit@latest/dist/index.global.js',
        function(){ if (!mountChatKitGlobal()) { mountChatKitModule(); } },
        function(){
          console.warn('[AgentBuilder] jsDelivr failed; trying unpkg...');
          loadScript(
            'https://unpkg.com/@openai/chatkit@latest/dist/index.global.js',
            function(){ if (!mountChatKitGlobal()) { mountChatKitModule(); } },
            function(){
              console.error('[AgentBuilder] Failed to load ChatKit library from CDNs. Falling back to ESM module.');
              mountChatKitModule();
            }
          );
        }
      );
    };
    document.head.appendChild(esm);
  } catch (e) {
    console.warn('[AgentBuilder] Module loader error; trying global builds...', e);
    loadScript(
      'https://cdn.jsdelivr.net/npm/@openai/chatkit@latest/dist/index.global.js',
      function(){ if (!mountChatKitGlobal()) { mountChatKitModule(); } },
      function(){
        console.warn('[AgentBuilder] jsDelivr failed; trying unpkg...');
        loadScript(
          'https://unpkg.com/@openai/chatkit@latest/dist/index.global.js',
          function(){ if (!mountChatKitGlobal()) { mountChatKitModule(); } },
          function(){
            console.error('[AgentBuilder] Failed to load ChatKit library from CDNs. Falling back to ESM module.');
            mountChatKitModule();
          }
        );
      }
    );
  }
})();
</script>

<?php
// End of component
?>


