const express = require('express');
const { createServer } = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const helmet = require('helmet');
const rateLimit = require('express-rate-limit');
const winston = require('winston');
const axios = require('axios');
const mysql = require('mysql2/promise');

// Initialize Express app
const app = express();
const server = createServer(app);

// Configure CORS for Socket.IO
const io = new Server(server, {
  cors: {
    origin: ["https://ai.solterrasol.com", "http://localhost", "http://127.0.0.1"],
    methods: ["GET", "POST"],
    credentials: true
  },
  transports: ['websocket', 'polling']
});

// Configure logging
const logger = winston.createLogger({
  level: 'info',
  format: winston.format.combine(
    winston.format.timestamp(),
    winston.format.json()
  ),
  transports: [
    new winston.transports.File({ filename: 'logs/error.log', level: 'error' }),
    new winston.transports.File({ filename: 'logs/combined.log' }),
    new winston.transports.Console({
      format: winston.format.simple()
    })
  ]
});

// Configuration
const CONFIG = {
  port: process.env.SUNNY_PORT || 3001,
  ollamaUrl: process.env.OLLAMA_URL || 'https://ai.solterrasol.com:11434',
  ollamaModel: process.env.OLLAMA_MODEL || 'gemma:2b',
  db: {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'SolterraSolutions',
    password: process.env.DB_PASSWORD || 'CompanyAdmin!',
    database: process.env.DB_NAME || 'solterra_portal'
  }
};

// Middleware
app.use(helmet());
app.use(cors());
app.use(express.json());

// Rate limiting
const limiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 100, // limit each IP to 100 requests per windowMs
  message: 'Too many requests from this IP'
});
app.use(limiter);

// Database connection pool
let dbPool;

async function initDatabase() {
  try {
    dbPool = mysql.createPool({
      host: CONFIG.db.host,
      user: CONFIG.db.user,
      password: CONFIG.db.password,
      database: CONFIG.db.database,
      waitForConnections: true,
      connectionLimit: 10,
      queueLimit: 0
    });
    
    logger.info('Database connection pool initialized');
  } catch (error) {
    logger.error('Failed to initialize database:', error);
  }
}

// Ollama Client Class
class OllamaClient {
  constructor() {
    this.baseUrl = CONFIG.ollamaUrl;
    this.model = CONFIG.ollamaModel;
  }

  async generateStreamingResponse(prompt, socket, messageId) {
    try {
      const response = await axios({
        method: 'post',
        url: `${this.baseUrl}/api/generate`,
        data: {
          model: this.model,
          prompt: prompt,
          stream: true,
          options: {
            temperature: 0.7,
            top_k: 40,
            top_p: 0.9,
            num_predict: 1000
          }
        },
        responseType: 'stream',
        timeout: 120000 // 2 minutes
      });

      socket.emit('message_start', { messageId });

      response.data.on('data', (chunk) => {
        const lines = chunk.toString().split('\n').filter(line => line.trim());
        
        for (const line of lines) {
          try {
            const data = JSON.parse(line);
            if (data.response) {
              socket.emit('message_chunk', { 
                messageId, 
                chunk: data.response 
              });
            }
            
            if (data.done) {
              socket.emit('message_end', { messageId });
            }
          } catch (parseError) {
            logger.warn('Failed to parse Ollama response chunk:', parseError);
          }
        }
      });

      response.data.on('end', () => {
        socket.emit('message_end', { messageId });
      });

      response.data.on('error', (error) => {
        logger.error('Ollama stream error:', error);
        socket.emit('error', { 
          messageId, 
          error: 'Streaming error occurred' 
        });
      });

    } catch (error) {
      logger.error('Ollama request failed:', error);
      socket.emit('error', { 
        messageId, 
        error: 'Failed to generate response' 
      });
    }
  }

  async checkHealth() {
    try {
      const response = await axios.get(`${this.baseUrl}/api/tags`, {
        timeout: 10000
      });
      return { available: true, status: response.status };
    } catch (error) {
      return { available: false, error: error.message };
    }
  }
}

// Initialize Ollama client
const ollamaClient = new OllamaClient();

// Sunny Tools Class (simplified for Node.js)
class SunnyTools {
  constructor(userRole, userAccountId) {
    this.userRole = userRole;
    this.userAccountId = userAccountId;
  }

  async executeQuery(sql, params = []) {
    try {
      // Add role-based filtering
      const filteredSql = this.applyRoleFiltering(sql);
      
      const [rows] = await dbPool.execute(filteredSql, params);
      return {
        success: true,
        data: rows,
        count: rows.length
      };
    } catch (error) {
      logger.error('Query execution error:', error);
      return {
        success: false,
        error: error.message
      };
    }
  }

  applyRoleFiltering(sql) {
    if (this.userRole === 'global_admin') {
      return sql;
    }

    // Add account_id filtering for non-global admins
    if (this.userAccountId && sql.toLowerCase().includes('from projects')) {
      if (sql.toLowerCase().includes('where')) {
        return sql.replace(/where/i, `WHERE account_id = ${this.userAccountId} AND`);
      } else {
        return sql.replace(/from projects/i, `FROM projects WHERE account_id = ${this.userAccountId}`);
      }
    }

    return sql;
  }

  async getProjectSummary() {
    const sql = `
      SELECT 
        p.id, p.project_name, p.status,
        COUNT(DISTINCT m.id) as total_modules,
        COUNT(DISTINCT d.id) as total_deliveries
      FROM projects p
      LEFT JOIN modules m ON p.id = m.project_id
      LEFT JOIN deliveries d ON p.id = d.project_id
      GROUP BY p.id 
      ORDER BY p.created_at DESC 
      LIMIT 10
    `;
    
    return this.executeQuery(sql);
  }

  async getDeliveryStatus() {
    const sql = `
      SELECT 
        d.delivery_id, d.status, d.carrier, d.tracking_number,
        p.project_name
      FROM deliveries d
      LEFT JOIN projects p ON d.project_id = p.id
      ORDER BY d.scheduled_delivery_date DESC 
      LIMIT 20
    `;
    
    return this.executeQuery(sql);
  }

  async getKPIDashboard() {
    const queries = {
      active_projects: "SELECT COUNT(*) as count FROM projects WHERE status = 'active'",
      pending_deliveries: "SELECT COUNT(*) as count FROM deliveries WHERE status = 'in_transit'",
      recent_deliveries: "SELECT COUNT(*) as count FROM deliveries WHERE status = 'delivered' AND actual_delivery_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    };

    const results = {};
    for (const [key, sql] of Object.entries(queries)) {
      const result = await this.executeQuery(sql);
      results[key] = result.success ? (result.data[0]?.count || 0) : 0;
    }

    return {
      success: true,
      data: results
    };
  }
}

// Session validation function
async function validateSession(authData) {
  try {
    // In a real implementation, you'd validate the PHP session
    // For now, we'll do basic validation
    if (!authData.userId || !authData.userRole) {
      return { valid: false, error: 'Invalid authentication data' };
    }

    // Optionally verify user exists in database
    const [rows] = await dbPool.execute(
      'SELECT id, username FROM users WHERE id = ?',
      [authData.userId]
    );

    if (rows.length === 0) {
      return { valid: false, error: 'User not found' };
    }

    return {
      valid: true,
      user: {
        id: authData.userId,
        role: authData.userRole,
        accountId: authData.userAccountId,
        username: rows[0].username
      }
    };
  } catch (error) {
    logger.error('Session validation error:', error);
    return { valid: false, error: 'Session validation failed' };
  }
}

// Socket.IO connection handling
io.on('connection', async (socket) => {
  logger.info(`Socket connected: ${socket.id}`);

  // Validate authentication
  const authData = socket.handshake.auth;
  const sessionValidation = await validateSession(authData);

  if (!sessionValidation.valid) {
    logger.warn(`Authentication failed for socket ${socket.id}: ${sessionValidation.error}`);
    socket.emit('auth_error', { error: sessionValidation.error });
    socket.disconnect();
    return;
  }

  const user = sessionValidation.user;
  logger.info(`User authenticated: ${user.username} (${user.role})`);

  // Initialize tools for this user
  const tools = new SunnyTools(user.role, user.accountId);

  // Handle user messages
  socket.on('user_message', async (data) => {
    try {
      const { message, timestamp } = data;
      const messageId = `msg_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;

      logger.info(`Message from ${user.username}: ${message}`);

      // Determine which tools to use based on message content
      const toolsToUse = parseToolUsage(message);
      const toolResults = {};

      // Execute relevant tools
      for (const toolName of toolsToUse) {
        try {
          let result;
          switch (toolName) {
            case 'getProjectSummary':
              result = await tools.getProjectSummary();
              break;
            case 'getDeliveryStatus':
              result = await tools.getDeliveryStatus();
              break;
            case 'getKPIDashboard':
              result = await tools.getKPIDashboard();
              break;
            default:
              continue;
          }
          toolResults[toolName] = result;
        } catch (error) {
          logger.error(`Tool ${toolName} failed:`, error);
          toolResults[toolName] = { success: false, error: error.message };
        }
      }

      // Build prompt with context and tool results
      const systemPrompt = await getSystemPrompt();
      const prompt = buildPrompt(systemPrompt, message, user, toolResults);

      // Generate streaming response
      await ollamaClient.generateStreamingResponse(prompt, socket, messageId);

    } catch (error) {
      logger.error('Message handling error:', error);
      socket.emit('error', { error: 'Failed to process message' });
    }
  });

  // Handle disconnection
  socket.on('disconnect', () => {
    logger.info(`Socket disconnected: ${socket.id}`);
  });
});

// Helper functions
function parseToolUsage(message) {
  const tools = [];
  const lowerMessage = message.toLowerCase();

  if (lowerMessage.includes('project') && (lowerMessage.includes('summary') || lowerMessage.includes('status'))) {
    tools.push('getProjectSummary');
  }

  if (lowerMessage.includes('deliver') || lowerMessage.includes('shipment')) {
    tools.push('getDeliveryStatus');
  }

  if (lowerMessage.includes('dashboard') || lowerMessage.includes('overview')) {
    tools.push('getKPIDashboard');
  }

  return tools;
}

async function getSystemPrompt() {
  return `You are Sunny, the virtual Logistics Analyst for Solterra Solutions' customer portal.

**Mission**
• Help customers retrieve reports, shipment documents, and status updates.
• Answer logistics questions in plain English, backed by Solterra data.
• Suggest next steps that drive smart, timely decisions.

**Tone & Style**
• Friendly, concise, and professional—think experienced analyst on Slack.
• When sharing numbers, include context.
• End each actionable reply with a short question that moves the conversation forward.

**Security & Privacy**
• Never reveal internal IDs, SQL, or stack traces.
• If unsure or the data isn't available, say so and offer to escalate to human support.`;
}

function buildPrompt(systemPrompt, userMessage, user, toolResults) {
  let prompt = systemPrompt + "\n\n";

  prompt += "**User Context:**\n";
  prompt += `Role: ${user.role}\n`;
  prompt += `Username: ${user.username}\n\n`;

  if (Object.keys(toolResults).length > 0) {
    prompt += "**Available Data:**\n";
    for (const [toolName, result] of Object.entries(toolResults)) {
      prompt += `Tool: ${toolName}\n`;
      if (result.success) {
        prompt += `Result: ${JSON.stringify(result.data, null, 2)}\n`;
      } else {
        prompt += `Error: ${result.error}\n`;
      }
      prompt += "\n";
    }
  }

  prompt += `**User Question:** ${userMessage}\n\n`;
  prompt += "**Response:**";

  return prompt;
}

// Health check endpoint
app.get('/health', async (req, res) => {
  const ollamaHealth = await ollamaClient.checkHealth();
  
  res.json({
    status: 'ok',
    timestamp: new Date().toISOString(),
    ollama: ollamaHealth,
    database: dbPool ? 'connected' : 'disconnected'
  });
});

// Start server
async function startServer() {
  try {
    await initDatabase();
    
    server.listen(CONFIG.port, () => {
      logger.info(`Sunny Chat Server running on port ${CONFIG.port}`);
      logger.info(`Ollama URL: ${CONFIG.ollamaUrl}`);
      logger.info(`Model: ${CONFIG.ollamaModel}`);
    });
  } catch (error) {
    logger.error('Failed to start server:', error);
    process.exit(1);
  }
}

// Graceful shutdown
process.on('SIGTERM', () => {
  logger.info('SIGTERM received, shutting down gracefully');
  server.close(() => {
    if (dbPool) {
      dbPool.end();
    }
    process.exit(0);
  });
});

process.on('SIGINT', () => {
  logger.info('SIGINT received, shutting down gracefully');
  server.close(() => {
    if (dbPool) {
      dbPool.end();
    }
    process.exit(0);
  });
});

// Start the server
startServer(); 