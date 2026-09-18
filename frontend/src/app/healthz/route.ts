import { NextResponse } from 'next/server';

export const dynamic = 'force-dynamic';

export async function GET() {
  return NextResponse.json({
    status: 'healthy',
    service: 'wabamini-frontend',
    timestamp: new Date().toISOString()
  });
}
